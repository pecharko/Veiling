#!/usr/bin/env python3
"""Restricted one-off deployment for reviewed audit PRs #25, #26, #27.

Only the six allowlisted runtime files are considered. Never touch databases,
uploads, snapshots, configuration, secrets, or unrelated production paths.
Runs with the existing pinned-SSH-host-key SFTP client.
"""
from __future__ import annotations

import argparse
import errno
import json
import posixpath
import stat
import sys
from pathlib import Path

from deploy_ovh_sftp import REPO, MAX_FILE_SIZE, connect, digest, remote_root

FILES = (
    "catalogus-public/assets/placeholder.svg",  # new or existing image asset
    "catalogus-public/admin/backup.php",          # outdated UI copy
    "catalogus-public/admin/kavel.php",           # outdated UI copy
    "catalogus-public/admin/admin.css",           # logout button styling
    "catalogus-public/admin/logout.php",          # POST-only logout
    "private/bootstrap.php",                      # POST logout form last
)
OPTIONAL_REMOTE = frozenset(("catalogus-public/assets/placeholder.svg",))
BACKUP_DIR = REPO / "deployment-backups"
RELEASE = "audit-pr25-26-27"


def verify_source(data: dict[str, bytes]) -> None:
    svg, backup, kavel, css, logout, bootstrap = (data[p] for p in FILES)
    if (b"<svg " not in svg or b"</svg>" not in svg
            or b"<script" in svg or b"foreignObject" in svg
            or b"http://www.w3.org/2000/svg" not in svg):
        raise RuntimeError("Unexpected or unsafe SVG placeholder")
    if (b"Maak na grote wijzigingen aan kavels" not in backup
            or b"gebruik je de foto-import" in kavel
            or b"lot-editor-form" not in kavel
            or b".admin-logout-button" not in css
            or b"require_auth();" not in logout
            or b"check_csrf();" not in logout
            or b"session_destroy();" not in logout
            or b"if (!is_post())" not in logout
            or b"function admin_top(" not in bootstrap
            or b"admin-logout-form" not in bootstrap
            or b"csrf_field()" not in bootstrap):
        raise RuntimeError("Sources are not the exact audited-release feature set")


def source_files() -> dict[str, bytes]:
    data: dict[str, bytes] = {}
    for name in FILES:
        path = REPO / name
        if not path.is_file() or path.is_symlink():
            raise RuntimeError(f"Source missing or symlinked: {name}")
        body = path.read_bytes()
        if not body or len(body) > MAX_FILE_SIZE:
            raise RuntimeError(f"Invalid source file size: {name}")
        data[name] = body
    verify_source(data)
    return data


def plan() -> None:
    print("AUDIT RELEASE: exactly six paths; no data, uploads, backups or configuration changes.")
    print("The SVG placeholder may be absent on production; missing originals are recorded.")
    for name, body in source_files().items():
        print(f"{name}: {len(body)} bytes sha256={digest(body)}")
    print("PLAN ONLY: no SFTP connection and no production writes.")


def at(root: str, name: str) -> str:
    return posixpath.join(root, name)


def optional_lstat(sftp, path: str):
    try:
        return sftp.lstat(path)
    except OSError as exc:
        if getattr(exc, "errno", None) == errno.ENOENT:
            return None
        raise


def read_file(sftp, path: str, *, optional: bool = False) -> bytes | None:
    info = optional_lstat(sftp, path) if optional else sftp.lstat(path)
    if info is None:
        return None
    if not stat.S_ISREG(info.st_mode) or not 1 <= info.st_size <= MAX_FILE_SIZE:
        raise RuntimeError(f"Missing, symlinked or invalid remote file: {path}")
    with sftp.open(path, "rb") as stream:
        body = stream.read(MAX_FILE_SIZE + 1)
    if not body or len(body) > MAX_FILE_SIZE:
        raise RuntimeError(f"Remote content invalid: {path}")
    return body


def remote_files(sftp, root: str) -> dict[str, bytes | None]:
    for folder in ("private", "catalogus-public",
                   "catalogus-public/admin", "catalogus-public/assets"):
        info = sftp.lstat(at(root, folder))
        if not stat.S_ISDIR(info.st_mode):
            raise RuntimeError(f"Remote directory is missing or symlinked: {folder}")

    # Read-only fingerprints prevent deploying to the wrong SFTP account/root.
    for name, marker in (
        ("private/bootstrap.php", b"function admin_top("),
        ("catalogus-public/admin/index.php", b"Kaveloverzicht"),
    ):
        body = read_file(sftp, at(root, name))
        if marker not in body:
            raise RuntimeError(f"Unknown remote application: {name}")

    result: dict[str, bytes | None] = {}
    for name in FILES:
        result[name] = read_file(sftp, at(root, name),
                                 optional=name in OPTIONAL_REMOTE)

    checks = {
        "catalogus-public/admin/backup.php": b"Snapshot maken",
        "catalogus-public/admin/kavel.php": b"lot-editor-form",
        "catalogus-public/admin/admin.css": b"--wine",
        "catalogus-public/admin/logout.php": b"session_destroy();",
        "private/bootstrap.php": b"function admin_top(",
    }
    for name, marker in checks.items():
        if marker not in result[name]:
            raise RuntimeError(f"Unexpected old production file: {name}")
    old_svg = result[FILES[0]]
    if old_svg is not None and (b"<svg" not in old_svg or b"</svg>" not in old_svg):
        raise RuntimeError("Existing production placeholder is not an SVG")
    return result


def backup() -> None:
    source = source_files()
    root = remote_root()
    if BACKUP_DIR.exists():
        raise RuntimeError("Backup directory already exists; refusing to overwrite")
    client = connect()
    try:
        with client.open_sftp() as sftp:
            original = remote_files(sftp, root)
            BACKUP_DIR.mkdir(mode=0o700)
            manifest = {
                "release": RELEASE, "base": root,
                "previous_sha256": {n: digest(v) if v is not None else None
                                    for n, v in original.items()},
                "new_sha256": {n: digest(v) for n, v in source.items()},
            }
            for name, value in original.items():
                if value is None:
                    print(f"Remote asset absent (will be new): {name}")
                    continue
                target = BACKUP_DIR / name
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(value)
                print(f"Backed up original: {name} {len(value)} bytes sha256={digest(value)}")
            (BACKUP_DIR / "manifest.json").write_text(
                json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    finally:
        client.close()
    print("Backup completed. No production changes.")


def load_backup(root: str, source: dict[str, bytes]) -> dict[str, bytes | None]:
    path = BACKUP_DIR / "manifest.json"
    if not path.is_file() or path.is_symlink():
        raise RuntimeError("Missing backup manifest")
    m = json.loads(path.read_text(encoding="utf-8"))
    old_hashes, new_hashes = m.get("previous_sha256"), m.get("new_sha256")
    if (m.get("release") != RELEASE or m.get("base") != root
            or not isinstance(old_hashes, dict)
            or not isinstance(new_hashes, dict)
            or set(old_hashes) != set(FILES) or set(new_hashes) != set(FILES)):
        raise RuntimeError("Backup manifest does not match exact release")
    original: dict[str, bytes | None] = {}
    for name in FILES:
        if new_hashes[name] != digest(source[name]):
            raise RuntimeError(f"Source drift since backup: {name}")
        expected = old_hashes[name]
        old_path = BACKUP_DIR / name
        if expected is None:
            if name not in OPTIONAL_REMOTE or old_path.exists():
                raise RuntimeError(f"Unexpected missing original: {name}")
            original[name] = None
            continue
        if not isinstance(expected, str) or not old_path.is_file() or old_path.is_symlink():
            raise RuntimeError(f"Missing original backup: {name}")
        old = old_path.read_bytes()
        if not old or digest(old) != expected:
            raise RuntimeError(f"Corrupt backup: {name}")
        original[name] = old
    return original


def verify_missing(sftp, path: str) -> bool:
    return optional_lstat(sftp, path) is None


def upload() -> None:
    source = source_files()
    root = remote_root()
    original = load_backup(root, source)
    client = connect()
    try:
        with client.open_sftp() as sftp:
            current = remote_files(sftp, root)
            if current != original:
                raise RuntimeError("Production changed after backup; stop for manual review")
            changed: list[str] = []
            try:
                for name in FILES:
                    if current[name] == source[name]:
                        print(f"Already current: {name}")
                        continue
                    changed.append(name)  # Include partially written targets in rollback.
                    target = at(root, name)
                    with sftp.open(target, "wb") as stream:
                        stream.write(source[name])
                        stream.flush()
                    actual = read_file(sftp, target)
                    if actual is None or digest(actual) != digest(source[name]):
                        raise RuntimeError(f"SHA-256 verification failure: {name}")
                    print(f"Uploaded and SHA-256 verified: {name}")
            except Exception:
                for name in reversed(changed):
                    target = at(root, name)
                    try:
                        old = original[name]
                        if old is None:
                            # This file did not exist before our release.
                            if not verify_missing(sftp, target):
                                sftp.remove(target)
                            if not verify_missing(sftp, target):
                                raise RuntimeError("New asset removal could not be verified")
                        else:
                            with sftp.open(target, "wb") as stream:
                                stream.write(old)
                                stream.flush()
                            actual = read_file(sftp, target)
                            if actual is None or digest(actual) != digest(old):
                                raise RuntimeError("Restoration SHA-256 mismatch")
                        print(f"Rollback verified: {name}", file=sys.stderr)
                    except Exception as exc:
                        print(f"URGENT manual restoration required for {name}: {exc}",
                              file=sys.stderr)
                raise
    finally:
        client.close()
    print("Six allowlisted runtime files verified. Check read-only HTTP smoke tests.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=("plan", "backup", "upload"))
    mode = parser.parse_args().mode
    try:
        {"plan": plan, "backup": backup, "upload": upload}[mode]()
    except Exception as exc:
        print(f"Release stopped: {exc}", file=sys.stderr)
        sys.exit(1)
