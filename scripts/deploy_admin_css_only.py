#!/usr/bin/env python3
"""Restricted CSS-only OVH deployment with rollback and SHA-256 verification.

Only catalogus-public/admin/admin.css is eligible for modification.
No live connection is made during 'plan'. Production deployment requires
approval via the separate manually dispatched GitHub Actions workflow.
"""
from __future__ import annotations

import argparse
import json
import posixpath
import stat
import sys

from deploy_ovh_sftp import connect, digest, remote_root, MAX_FILE_SIZE, REPO

CSS = "catalogus-public/admin/admin.css"
BACKUP_DIR = REPO / "deployment-backups"
BACKUP_FILE = BACKUP_DIR / CSS
MANIFEST = BACKUP_DIR / "manifest.json"


def source() -> bytes:
    path = REPO / CSS
    if not path.is_file() or path.is_symlink():
        raise RuntimeError("CSS source is missing or a symlink")
    data = path.read_bytes()
    if not 30 <= len(data) <= MAX_FILE_SIZE or b".dashboard-modern" not in data:
        raise RuntimeError("Unexpected CSS source content or size")
    return data


def plan() -> None:
    data = source()
    print(f"CSS-only allowlist: {CSS}")
    print(f"Source: {len(data)} bytes sha256={digest(data)}")
    print("Plan only: no connection and no writes.")


def remote_css(sftp, root: str) -> bytes:
    # Verify the correct application tree before touching anything.
    with sftp.open(posixpath.join(root, "private/bootstrap.php"), "rb") as f:
        if b"function admin_top" not in f.read(MAX_FILE_SIZE + 1):
            raise RuntimeError("Unexpected remote bootstrap: check SFTP base")
    with sftp.open(posixpath.join(root, "catalogus-public/admin/index.php"), "rb") as f:
        if b"Kaveloverzicht" not in f.read(MAX_FILE_SIZE + 1):
            raise RuntimeError("Unexpected remote admin: check SFTP base")

    path = posixpath.join(root, CSS)
    info = sftp.stat(path)
    if not stat.S_ISREG(info.st_mode) or not 30 <= info.st_size <= MAX_FILE_SIZE:
        raise RuntimeError("Production CSS file missing, invalid or too large")
    with sftp.open(path, "rb") as f:
        data = f.read(MAX_FILE_SIZE + 1)
    if not 30 <= len(data) <= MAX_FILE_SIZE or b".dashboard-modern" not in data:
        raise RuntimeError("Unexpected production CSS: manual review required")
    return data


def backup() -> None:
    source()
    root = remote_root()
    if BACKUP_DIR.exists():
        raise RuntimeError("Local backup directory already exists: refuse overwrite")

    client = connect()
    try:
        with client.open_sftp() as sftp:
            original = remote_css(sftp, root)
            BACKUP_FILE.parent.mkdir(parents=True, mode=0o700)
            BACKUP_FILE.write_bytes(original)
            MANIFEST.write_text(json.dumps({
                "release": "css-only",
                "base": root,
                "path": CSS,
                "sha256": digest(original),
            }, indent=2) + "\n", encoding="utf-8")
            print(f"Backed up {CSS}: {len(original)} bytes, sha256={digest(original)}")
    finally:
        client.close()
    print("Backup complete; no production changes.")


def upload() -> None:
    new = source()
    root = remote_root()
    if not MANIFEST.is_file() or not BACKUP_FILE.is_file():
        raise RuntimeError("Missing validated pre-upload backup")
    manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
    if (manifest.get("release") != "css-only"
            or manifest.get("base") != root
            or manifest.get("path") != CSS):
        raise RuntimeError("Backup manifest is for another release or remote root")
    old = BACKUP_FILE.read_bytes()
    if digest(old) != manifest.get("sha256"):
        raise RuntimeError("Backup checksum mismatch: abort")

    client = connect()
    try:
        with client.open_sftp() as sftp:
            current = remote_css(sftp, root)
            if current != old:
                raise RuntimeError("Production CSS changed after backup: abort")
            if current == new:
                print("Production CSS already matches; no write performed.")
                return

            target = posixpath.join(root, CSS)
            try:
                with sftp.open(target, "wb") as f:
                    f.write(new)
                    f.flush()
                with sftp.open(target, "rb") as f:
                    uploaded = f.read(MAX_FILE_SIZE + 1)
                if digest(uploaded) != digest(new):
                    raise RuntimeError("Uploaded CSS SHA-256 mismatch")
                print(f"CSS uploaded and SHA-256 verified: {digest(new)}")
            except Exception:
                # Best-effort restore, including verification of restored bytes.
                try:
                    with sftp.open(target, "wb") as f:
                        f.write(old)
                        f.flush()
                    with sftp.open(target, "rb") as f:
                        restored = f.read(MAX_FILE_SIZE + 1)
                    if digest(restored) != digest(old):
                        raise RuntimeError("Restored CSS SHA-256 mismatch")
                    print("Original CSS restored and verified", file=sys.stderr)
                except Exception as exc:
                    print(f"MANUAL ROLLBACK REQUIRED: {exc}", file=sys.stderr)
                raise
    finally:
        client.close()


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=("plan", "backup", "upload"))
    args = parser.parse_args()
    try:
        {"plan": plan, "backup": backup, "upload": upload}[args.mode]()
    except Exception as exc:
        print(f"Stopped: {exc}", file=sys.stderr)
        sys.exit(1)
