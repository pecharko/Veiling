#!/usr/bin/env python3
"""Restricted SFTP deployment for modern admin dashboard HTML and CSS.

This deliberately does not modify or widen the proven CSV-disable release list.
Production uploads require explicit manual GitHub Actions approval.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import posixpath
import stat
import sys

from deploy_ovh_sftp import connect, digest, remote_root, MAX_FILE_SIZE, REPO

RELEASE = (
    "catalogus-public/admin/admin.css",
    "catalogus-public/admin/index.php",
)
BACKUP_DIR = REPO / "deployment-backups"
EDITOR = RELEASE[-1]


def files() -> dict[str, bytes]:
    result = {}
    for path in RELEASE:
        file = REPO / path
        if not file.is_file() or file.is_symlink():
            raise RuntimeError(f"Missing release file: {path}")
        data = file.read_bytes()
        if len(data) < 30 or len(data) > MAX_FILE_SIZE:
            raise RuntimeError(f"Invalid file size: {path}")
        result[path] = data
    if b'dashboard-modern' not in result[EDITOR] or b'.dashboard-modern' not in result[RELEASE[0]]:
        raise RuntimeError('Dashboard PHP and CSS are not matching release versions.')
    return result


def plan() -> None:
    print("Standalone modern dashboard release; other files and data are untouched.")
    for name, data in files().items():
        print(f"{name}: {len(data)} bytes sha256={digest(data)}")
    print("Plan only: no connection and no writes.")


def target(root: str, name: str) -> str:
    return posixpath.join(root, name)


def read_remote(sftp, root: str) -> dict[str, bytes | None]:
    # Check roots independently, so a wrong SFTP_BASE cannot create PHP files
    # in some unrelated hosting directory.
    with sftp.open(target(root, "private/bootstrap.php"), "rb") as f:
        if b"function admin_top" not in f.read(MAX_FILE_SIZE + 1):
            raise RuntimeError("Unrecognized application bootstrap on remote.")
    with sftp.open(target(root, "catalogus-public/admin/index.php"), "rb") as f:
        if b"Kaveloverzicht" not in f.read(MAX_FILE_SIZE + 1):
            raise RuntimeError("Unrecognized auction admin on remote.")
    data = {}
    for name in RELEASE:
        try:
            info = sftp.stat(target(root, name))
        except FileNotFoundError:
            raise RuntimeError(f"Existing production file missing: {name}")
        if not stat.S_ISREG(info.st_mode) or info.st_size > MAX_FILE_SIZE:
            raise RuntimeError(f"Remote file invalid or too large: {name}")
        with sftp.open(target(root, name), "rb") as f:
            content = f.read(MAX_FILE_SIZE + 1)
        if not content or len(content) > MAX_FILE_SIZE:
            raise RuntimeError(f"Remote file empty or too large: {name}")
        data[name] = content
    if b"Kaveloverzicht" not in (data[EDITOR] or b""):
        raise RuntimeError("Unexpected remote dashboard; review manually.")
    return data


def backup() -> None:
    files()
    root = remote_root()
    if BACKUP_DIR.exists():
        raise RuntimeError("Backup folder already exists; refuse to overwrite.")
    client = connect()
    try:
        with client.open_sftp() as sftp:
            originals = read_remote(sftp, root)
            BACKUP_DIR.mkdir(mode=0o700)
            manifest = {"base": root, "release": "modern-dashboard", "sha256": {}}
            for name, data in originals.items():
                manifest["sha256"][name] = digest(data) if data is not None else None
                if data is not None:
                    path = BACKUP_DIR / name
                    path.parent.mkdir(parents=True, exist_ok=True)
                    path.write_bytes(data)
                    print(f"Backed up: {name}, {len(data)} bytes")
                else:
                    print(f"New file, not previously present: {name}")
            (BACKUP_DIR / "manifest.json").write_text(
                json.dumps(manifest, indent=2) + "\n", encoding="utf-8"
            )
    finally:
        client.close()
    print("Verified backup complete. No changes to production.")


def upload() -> None:
    source = files()
    root = remote_root()
    path = BACKUP_DIR / "manifest.json"
    if not path.is_file():
        raise RuntimeError("Missing pre-upload backup manifest.")
    manifest = json.loads(path.read_text(encoding="utf-8"))
    if (manifest.get("base") != root or manifest.get("release") != "modern-dashboard"
            or set(manifest.get("sha256", {})) != set(RELEASE)):
        raise RuntimeError("Incorrect backup manifest.")
    old = {}
    for name, sha in manifest["sha256"].items():
        old[name] = (BACKUP_DIR / name).read_bytes() if sha is not None else None
        if old[name] is not None and digest(old[name]) != sha:
            raise RuntimeError(f"Backup checksum invalid: {name}")

    client = connect()
    try:
        with client.open_sftp() as sftp:
            current = read_remote(sftp, root)
            if current != old:
                raise RuntimeError("Remote files changed since backup; aborting upload.")
            changed: list[str] = []
            try:
                for name in RELEASE:
                    if current[name] == source[name]:
                        print(f"Identical on remote: {name}")
                        continue
                    changed.append(name)  # Include partial writes in rollback.
                    with sftp.open(target(root, name), "wb") as f:
                        f.write(source[name])
                        f.flush()
                    with sftp.open(target(root, name), "rb") as f:
                        if digest(f.read(MAX_FILE_SIZE + 1)) != digest(source[name]):
                            raise RuntimeError(f"Verification failed: {name}")
                    print(f"Uploaded and verified: {name}")
            except Exception:
                for name in reversed(changed):
                    try:
                        if old[name] is None:
                            sftp.remove(target(root, name))
                        else:
                            with sftp.open(target(root, name), "wb") as f:
                                f.write(old[name])
                                f.flush()
                        print(f"Rolled back: {name}", file=sys.stderr)
                    except Exception:
                        print(f"MANUAL ROLLBACK REQUIRED: {name}", file=sys.stderr)
                raise
    finally:
        client.close()
    print("Modern dashboard SFTP release finished; browser-level validation still required.")


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("mode", choices=("plan", "backup", "upload"))
    args = ap.parse_args()
    try:
        {"plan": plan, "backup": backup, "upload": upload}[args.mode]()
    except Exception as exc:
        print(f"Stopped: {exc}", file=sys.stderr)
        sys.exit(1)
