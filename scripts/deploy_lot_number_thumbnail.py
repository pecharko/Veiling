#!/usr/bin/env python3
"""Manual, allowlisted OVHcloud SFTP release with pre-upload backups.

Only the four approved lot-number and thumbnail release files are transferred. The actual
upload requires an explicit GitHub Actions workflow_dispatch confirmation.
"""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import os
from pathlib import Path
import posixpath
import stat
import sys
import tempfile

REPO = Path(__file__).resolve().parents[1]
BACKUP_DIR = REPO / "deployment-backups"
FILES = (
    "catalogus-public/admin/kavel.php",
    "private/lot_form.php",
    "catalogus-public/admin/admin.css",
    "catalogus-public/admin/index.php",
)
MAX_FILE_SIZE = 2 * 1024 * 1024


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def source_files() -> dict[str, bytes]:
    results = {}
    for name in FILES:
        path = REPO / name
        if not path.is_file() or path.is_symlink():
            raise RuntimeError(f"Releasebestand ontbreekt of is een symlink: {name}")
        contents = path.read_bytes()
        if not contents or len(contents) > MAX_FILE_SIZE:
            raise RuntimeError(f"Onverwachte bestandsgrootte: {name}")
        results[name] = contents
    return results


def plan() -> None:
    for name, data in source_files().items():
        print(f"{name}: {len(data)} bytes sha256={digest(data)}")
    print("Plan is alleen lokaal gecontroleerd; niets naar OVHcloud verzonden.")


def remote_root() -> str:
    root = os.environ.get("OVH_SFTP_BASE", "").strip().rstrip("/")
    if not root:
        raise RuntimeError("Stel repositoryvariabele OVH_SFTP_BASE expliciet in.")
    if ".." in root.split("/") or any(c in root for c in "\r\n\\"):
        raise RuntimeError("Ongeldige OVH_SFTP_BASE.")
    return root or "/"


def remote_path(root: str, name: str) -> str:
    return posixpath.join(root, name)


def connect():
    # Import only during remote operations, not during plan/CI validation.
    import paramiko

    host = os.environ.get("OVH_SFTP_HOST", "").strip()
    username = os.environ.get("OVH_SFTP_USER", "").strip()
    password = os.environ.get("OVH_SFTP_PASSWORD", "")
    known_hosts = os.environ.get("OVH_SFTP_KNOWN_HOSTS", "").strip()
    if not all((host, username, password, known_hosts)):
        raise RuntimeError("OVH_SFTP_HOST/USER/PASSWORD/KNOWN_HOSTS ontbreken.")
    if any(c in host for c in " /\\\r\n") or host.startswith("-"):
        raise RuntimeError("Ongeldige SFTP-host.")

    # Host key is pinned: never auto-accept unknown SSH servers.
    with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", delete=False) as f:
        f.write(known_hosts + "\n")
        key_file = f.name
    client = paramiko.SSHClient()
    try:
        client.load_host_keys(key_file)
    finally:
        os.unlink(key_file)
    client.set_missing_host_key_policy(paramiko.RejectPolicy())
    client.connect(hostname=host, port=22, username=username, password=password,
                   look_for_keys=False, allow_agent=False, timeout=20,
                   auth_timeout=20, banner_timeout=20)
    return client


def fetch_remote(sftp, root: str) -> dict[str, bytes]:
    existing = {}
    for name in FILES:
        target = remote_path(root, name)
        info = sftp.lstat(target)
        if not stat.S_ISREG(info.st_mode) or info.st_size > MAX_FILE_SIZE:
            raise RuntimeError(f"Remote bestand ontbreekt/is te groot: {name}")
        with sftp.open(target, "rb") as remote:
            data = remote.read(MAX_FILE_SIZE + 1)
        if not data or len(data) > MAX_FILE_SIZE:
            raise RuntimeError(f"Remote bestand ongeldig: {name}")
        existing[name] = data
    # Verify exact remote root using existing known admin code.
    if b"lot_number" not in existing["catalogus-public/admin/kavel.php"]:
        raise RuntimeError("Unexpected admin kavel.php in remote root")
    if b"lot_form_values" not in existing["private/lot_form.php"]:
        raise RuntimeError("Unexpected private/lot_form.php in remote root")
    if b"dashboard-table" not in existing["catalogus-public/admin/index.php"]:
        raise RuntimeError("Unexpected admin index.php in remote root")
    return existing


def preflight() -> None:
    root = remote_root()
    client = connect()
    try:
        with client.open_sftp() as sftp:
            current = fetch_remote(sftp, root)
            for name, data in current.items():
                print("REMOTE", name, digest(data))
            print("READ ONLY")
    finally:
        client.close()


def backup() -> None:
    source_files()
    root = remote_root()
    client = connect()
    try:
        with client.open_sftp() as sftp:
            existing = fetch_remote(sftp, root)
            if BACKUP_DIR.exists():
                raise RuntimeError("Back-upmap bestaat al; stop om overschrijven te vermijden.")
            BACKUP_DIR.mkdir(mode=0o700)
            hashes = {}
            for name, data in existing.items():
                target = BACKUP_DIR / name
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(data)
                hashes[name] = digest(data)
                print(f"Veiligheidskopie opgehaald: {name}, {len(data)} bytes")
            (BACKUP_DIR / "manifest.json").write_text(
                json.dumps({"base": root, "sha256": hashes}, indent=2) + "\n",
                encoding="utf-8",
            )
    finally:
        client.close()
    print("Remote bestanden zijn opgehaald, geen wijzigingen op OVHcloud.")


def upload() -> None:
    source = source_files()
    root = remote_root()
    expected = os.environ.get("RELEASE_EXPECTED_HASHES", "").split(",")
    if len(expected) != len(FILES) or any(len(h) != 64 or any(ch not in "0123456789abcdef" for ch in h) for h in expected):
        raise RuntimeError("Four preflight hashes required")
    manifest_path = BACKUP_DIR / "manifest.json"
    if not manifest_path.is_file():
        raise RuntimeError("Veiligheidskopie ontbreekt: eerst de backup-stap uitvoeren.")
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    if manifest.get("base") != root or set(manifest.get("sha256", {})) != set(FILES):
        raise RuntimeError("Back-up hoort bij andere map of release.")

    client = connect()
    try:
        with client.open_sftp() as sftp:
            current = fetch_remote(sftp, root)
            for name, expected_hash in zip(FILES, expected):
                if digest(current[name]) != expected_hash:
                    raise RuntimeError("STOP: preflight drift: " + name)
            for name, data in current.items():
                previous = (BACKUP_DIR / name).read_bytes()
                if digest(previous) != manifest["sha256"][name] or data != previous:
                    raise RuntimeError(f"Remote bestand is intussen gewijzigd: {name}; stop.")
            changed = []
            try:
                for name in FILES:
                    if current[name] == source[name]:
                        print(f"Ongewijzigd: {name}")
                        continue
                    target = remote_path(root, name)
                    # Upload existing PHP file in place; original is stored in artifact.
                    # Include this file in rollback even if an upload breaks halfway.
                    changed.append(name)
                    with sftp.open(target, "wb") as remote:
                        remote.write(source[name])
                        remote.flush()
                    with sftp.open(target, "rb") as verify:
                        if digest(verify.read()) != digest(source[name]):
                            raise RuntimeError(f"Checksumverschil na upload: {name}")
                    print(f"Geplaatst en gecontroleerd: {name}")
            except Exception:
                # Best-effort restore from backups if any upload fails.
                for name in reversed(changed):
                    try:
                        with sftp.open(remote_path(root, name), "wb") as remote:
                            remote.write(current[name])
                            remote.flush()
                        print(f"Teruggezet na fout: {name}", file=sys.stderr)
                    except Exception:
                        print(f"DRINGEND: herstel handmatig uit back-upartifact: {name}", file=sys.stderr)
                raise
    finally:
        client.close()
    print("De vier kavelreleasebestanden zijn veilig verwerkt.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=("plan", "preflight", "backup", "upload"))
    mode = parser.parse_args().mode
    try:
        {"plan": plan, "preflight": preflight, "backup": backup, "upload": upload}[mode]()
    except Exception as exc:
        print(f"Deploy gestopt: {exc}", file=sys.stderr)
        sys.exit(1)
