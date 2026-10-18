#!/usr/bin/env python3
"""Manually approved retirement of unsupported SFTP bulk photo import.

Only three existing PHP code files are deployed. Never touch photo-inbox,
lot images, uploads, database, snapshots, or production configuration.
"""
from __future__ import annotations

import argparse
import json
import posixpath
import stat
import sys
from pathlib import Path

from deploy_ovh_sftp import REPO, MAX_FILE_SIZE, connect, digest, remote_root

FILES = (
    "catalogus-public/admin/fotos-bulk.php",    # close old endpoint first
    "catalogus-public/admin/systeemcheck.php",  # remove obsolete check second
    "private/bootstrap.php",                    # remove navigation link last
)
BACKUP_DIR = REPO / "deployment-backups"
RELEASE = "retire-photo-bulk-import"


def source_files() -> dict[str, bytes]:
    data: dict[str, bytes] = {}
    for name in FILES:
        path = REPO / name
        if not path.is_file() or path.is_symlink():
            raise RuntimeError(f"Missing or symlinked release file: {name}")
        body = path.read_bytes()
        if not body or len(body) > MAX_FILE_SIZE:
            raise RuntimeError(f"Invalid source file size: {name}")
        data[name] = body

    retired, health, nav = (data[name] for name in FILES)
    if (b"http_response_code(404);" not in retired
            or b"exit;" not in retired
            or b"require " in retired
            or b"add_lot_image(" in retired
            or b"function report_item(" not in health
            or b"Afgeschermde fotobulkmap" in health
            or b"function admin_top(" not in nav
            or b"fotos-bulk.php" in nav
            or b"Foto-import" in nav):
        raise RuntimeError("Source does not match retired bulk-photo feature.")
    return data


def plan() -> None:
    print("Disable unsupported bulk photo import; code-only release of three PHP files.")
    print("No database, photo-inbox, uploads, photos, snapshots, or configuration changes.")
    for name, body in source_files().items():
        print(f"{name}: {len(body)} bytes sha256={digest(body)}")
    print("PLAN ONLY: no SFTP connection and no production writes.")


def at(root: str, name: str) -> str:
    return posixpath.join(root, name)


def verified_remote(sftp, root: str) -> dict[str, bytes]:
    # Reject unexpected remote mount/tree. Never create directories or follow symlinks.
    for path in ("private", "catalogus-public", "catalogus-public/admin"):
        st = sftp.lstat(at(root, path))
        if not stat.S_ISDIR(st.st_mode):
            raise RuntimeError(f"Remote path is not a genuine directory: {path}")
    for name, signature in (
        ("private/bootstrap.php", b"function admin_top"),
        ("catalogus-public/admin/index.php", b"Kaveloverzicht"),
    ):
        p = at(root, name)
        info = sftp.lstat(p)
        if not stat.S_ISREG(info.st_mode) or info.st_size > MAX_FILE_SIZE:
            raise RuntimeError(f"Unrecognized production project: {name}")
        with sftp.open(p, "rb") as f:
            if signature not in f.read(MAX_FILE_SIZE + 1):
                raise RuntimeError(f"Unexpected production marker: {name}")
    result: dict[str, bytes] = {}
    for name in FILES:
        p = at(root, name)
        info = sftp.lstat(p)
        if not stat.S_ISREG(info.st_mode) or info.st_size > MAX_FILE_SIZE:
            raise RuntimeError(f"Missing, symlinked or oversized production file: {name}")
        with sftp.open(p, "rb") as f:
            data = f.read(MAX_FILE_SIZE + 1)
        if not data or len(data) > MAX_FILE_SIZE:
            raise RuntimeError(f"Cannot safely read full production file: {name}")
        result[name] = data
    old_bulk = result[FILES[0]]
    if (not (b"photo-inbox" in old_bulk
             or b"http_response_code(404);" in old_bulk)
            or b"function report_item(" not in result[FILES[1]]
            or b"function admin_top(" not in result[FILES[2]]):
        raise RuntimeError("Unexpected production bulk-import or admin files.")


    return result


def backup() -> None:
    source = source_files()
    root = remote_root()
    if BACKUP_DIR.exists():
        raise RuntimeError("Backup directory already exists. Refusing to overwrite.")
    client = connect()
    try:
        with client.open_sftp() as sftp:
            old = verified_remote(sftp, root)
            BACKUP_DIR.mkdir(mode=0o700)
            manifest = {
                "release": RELEASE, "base": root,
                "previous_sha256": {name: digest(body) for name, body in old.items()},
                "new_sha256": {name: digest(body) for name, body in source.items()},
            }
            for name, body in old.items():
                p = BACKUP_DIR / name
                p.parent.mkdir(parents=True, exist_ok=True)
                p.write_bytes(body)
                print(f"Backed up existing code: {name} ({len(body)} bytes)")
            (BACKUP_DIR / "manifest.json").write_text(
                json.dumps(manifest, indent=2) + "\n", encoding="utf-8"
            )
    finally:
        client.close()
    print("Code backup verified. Nothing on production changed.")


def upload() -> None:
    source = source_files()
    root = remote_root()
    manifest_file = BACKUP_DIR / "manifest.json"
    if not manifest_file.is_file():
        raise RuntimeError("Missing backup manifest: do not deploy without backup.")
    manifest = json.loads(manifest_file.read_text(encoding="utf-8"))
    if (manifest.get("release") != RELEASE
            or manifest.get("base") != root
            or set(manifest.get("previous_sha256", {})) != set(FILES)
            or set(manifest.get("new_sha256", {})) != set(FILES)):
        raise RuntimeError("Manifest does not belong to this exact release.")
    old: dict[str, bytes] = {}
    for name in FILES:
        p = BACKUP_DIR / name
        if not p.is_file() or p.is_symlink():
            raise RuntimeError(f"Original file backup missing: {name}")
        body = p.read_bytes()
        if not body or digest(body) != manifest["previous_sha256"][name]:
            raise RuntimeError(f"Original file backup checksum invalid: {name}")
        if digest(source[name]) != manifest["new_sha256"][name]:
            raise RuntimeError(f"Source code changed after backup: {name}")
        old[name] = body

    client = connect()
    try:
        with client.open_sftp() as sftp:
            current = verified_remote(sftp, root)
            if current != old:
                raise RuntimeError("Production files changed since backup. STOP for drift review.")
            changed: list[str] = []
            try:
                for name in FILES:
                    if current[name] == source[name]:
                        print(f"Already up to date: {name}")
                        continue
                    changed.append(name)  # Include partial writes in rollback.
                    with sftp.open(at(root, name), "wb") as f:
                        f.write(source[name])
                        f.flush()
                    with sftp.open(at(root, name), "rb") as f:
                        if digest(f.read(MAX_FILE_SIZE + 1)) != digest(source[name]):
                            raise RuntimeError(f"Read-back checksum mismatch: {name}")
                    print(f"Uploaded and SHA256-verified: {name}")
            except Exception:
                for name in reversed(changed):
                    try:
                        with sftp.open(at(root, name), "wb") as f:
                            f.write(old[name])
                            f.flush()
                        with sftp.open(at(root, name), "rb") as f:
                            if digest(f.read(MAX_FILE_SIZE + 1)) != digest(old[name]):
                                raise RuntimeError("Rollback checksum mismatch")
                        print(f"Rollback verified: {name}", file=sys.stderr)
                    except Exception:
                        print(f"URGENT MANUAL RECOVERY REQUIRED: {name}", file=sys.stderr)
                raise
    finally:
        client.close()
    print("Three approved PHP files deployed and SHA256-verified. HTTP verification still required.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=("plan", "backup", "upload"))
    mode = parser.parse_args().mode
    try:
        {"plan": plan, "backup": backup, "upload": upload}[mode]()
    except Exception as exc:
        print(f"Release stopped: {exc}", file=sys.stderr)
        sys.exit(1)
