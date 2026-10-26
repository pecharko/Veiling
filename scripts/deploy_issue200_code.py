#!/usr/bin/env python3
"""Fail-closed four-file issue 200 release; no database operations."""
import hashlib
import json
import os
from pathlib import Path
import posixpath
import stat
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
FILES = (
    "catalogus-public/admin/gebruikers.php",
    "catalogus-public/admin/login.php",
    "private/snapshots.php",
    "private/bootstrap.php",
)
NEW_FILES = set()
BACKUP = ROOT / "deployment-backups"
LIMIT = 2 * 1024 * 1024

def sha(data):
    return hashlib.sha256(data).hexdigest()

def source():
    result = {}
    for name in FILES:
        path = ROOT / name
        if path.is_symlink() or not path.is_file():
            raise RuntimeError("Missing/unsafe source: " + name)
        data = path.read_bytes()
        if not data or len(data) > LIMIT:
            raise RuntimeError("Invalid size: " + name)
        result[name] = data
    return result

def connect():
    import paramiko
    values = [os.environ.get(k, "").strip() for k in (
        "OVH_SFTP_HOST", "OVH_SFTP_USER", "OVH_SFTP_PASSWORD", "OVH_SFTP_KNOWN_HOSTS", "OVH_SFTP_BASE"
    )]
    if not all(values):
        raise RuntimeError("OVH secrets or root are missing")
    host, user, password, keys, base = values
    if any(c in host for c in " /\\\r\n") or host.startswith("-"):
        raise RuntimeError("Invalid SFTP host")
    if ".." in base.split("/") or any(c in base for c in "\\r\n\\\\"):
        raise RuntimeError("Invalid remote root")
    tmp = tempfile.NamedTemporaryFile("w", delete=False)
    try:
        tmp.write(keys + "\n")
        tmp.close()
        client = paramiko.SSHClient()
        client.load_host_keys(tmp.name)
    finally:
        os.unlink(tmp.name)
    client.set_missing_host_key_policy(paramiko.RejectPolicy())
    client.connect(host, port=22, username=user, password=password,
                   look_for_keys=False, allow_agent=False, timeout=20)
    return client, base.rstrip("/")

def remote_read(sftp, path, allow_absent=False):
    try:
        info = sftp.lstat(path)
    except OSError as exc:
        if allow_absent and getattr(exc, "errno", None) == 2:
            return None
        raise
    if not stat.S_ISREG(info.st_mode) or info.st_size > LIMIT:
        raise RuntimeError("Remote is not a safe regular file: " + path)
    with sftp.open(path, "rb") as f:
        data = f.read(LIMIT + 1)
    if not data or len(data) > LIMIT:
        raise RuntimeError("Unexpected remote size: " + path)
    return data

def inspect(sftp, base):
    sentinel = remote_read(sftp, posixpath.join(base, "catalogus-public/admin/index.php"))
    if b"dashboard-table" not in sentinel:
        raise RuntimeError("Wrong remote base (admin index sentinel)")
    result = {}
    for name in FILES:
        result[name] = remote_read(sftp, posixpath.join(base, name), name in NEW_FILES)
    if result["private/bootstrap.php"] is None or b"function require_auth()" not in result["private/bootstrap.php"]:
        raise RuntimeError("Wrong remote bootstrap")
    return result

def hashes(items):
    return {name: ("ABSENT" if data is None else sha(data)) for name, data in items.items()}

def plan():
    for name, data in source().items():
        print("SOURCE", name, sha(data))
    print("PLAN ONLY; no SFTP changes.")

def preflight():
    client, base = connect()
    try:
        with client.open_sftp() as sftp:
            actual = hashes(inspect(sftp, base))
            for name, value in actual.items():
                print("REMOTE", name, value)
            out = os.environ.get("PREFLIGHT_MANIFEST_PATH")
            if out:
                if Path(out).exists():
                    raise RuntimeError("Refusing to overwrite manifest")
                Path(out).write_text(json.dumps({
                    "sha": os.environ["GITHUB_SHA"],
                    "run_id": os.environ["GITHUB_RUN_ID"],
                    "remote": actual,
                }, indent=2) + "\n")
            print("READ ONLY")
    finally:
        client.close()

def deploy():
    expected = os.environ.get("EXPECTED_REMOTE_HASHES", "").split(",")
    if len(expected) != len(FILES) or any(h != "ABSENT" and
            (len(h) != 64 or any(c not in "0123456789abcdef" for c in h)) for h in expected):
        raise RuntimeError("All four preflight hashes required")
    if any(h == "ABSENT" for h in expected):
        raise RuntimeError("Unexpected existing/absent file baselines")
    payload = source()
    client, base = connect()
    try:
        with client.open_sftp() as sftp:
            prior = inspect(sftp, base)
            for name, digest in zip(FILES, expected):
                if hashes({name: prior[name]})[name] != digest:
                    raise RuntimeError("STOP: remote hash drift at " + name)
            if BACKUP.exists():
                raise RuntimeError("Backup folder already exists")
            BACKUP.mkdir(mode=0o700)
            for name, data in prior.items():
                if data is not None:
                    target = BACKUP / name
                    target.parent.mkdir(parents=True, exist_ok=True)
                    target.write_bytes(data)
            (BACKUP / "manifest.json").write_text(json.dumps({
                "remote_root": base, "before": hashes(prior),
                "source": hashes(payload), "files": FILES,
            }, indent=2) + "\n")
            changed = []
            try:
                # Login and UI first, bootstrap last; the schema migration is a prerequisite.
                for name in FILES:
                    if prior[name] == payload[name]:
                        print("UNCHANGED", name)
                        continue
                    path = posixpath.join(base, name)
                    changed.append(name)
                    with sftp.open(path, "wb") as f:
                        f.write(payload[name])
                        f.flush()
                    if remote_read(sftp, path) != payload[name]:
                        raise RuntimeError("Remote verification failed at " + name)
                    print("VERIFIED", name, sha(payload[name]))
            except Exception:
                for name in reversed(changed):
                    path = posixpath.join(base, name)
                    try:
                        if prior[name] is None:
                            sftp.remove(path)
                        else:
                            with sftp.open(path, "wb") as f:
                                f.write(prior[name])
                                f.flush()
                        if remote_read(sftp, path, True) != prior[name]:
                            raise RuntimeError("Rollback readback mismatch")
                        print("ROLLBACK VERIFIED", name, file=sys.stderr)
                    except Exception as exc:
                        print("CRITICAL MANUAL RESTORE NEEDED", name, str(exc), file=sys.stderr)
                raise
    finally:
        client.close()

if __name__ == "__main__":
    try:
        action = sys.argv[1] if len(sys.argv) == 2 else ""
        {"plan":plan, "preflight":preflight, "deploy":deploy}[action]()
    except (Exception, KeyError) as exc:
        print("RELEASE STOPPED:", exc, file=sys.stderr)
        sys.exit(1)
