#!/usr/bin/env python3
"""Offline, read-only release preflight for the eight catalog recovery runtime files.

No SFTP, network, DB access, backups, production changes or deploy command.
Output is a source SHA-256 manifest for further *human* release evaluation.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
MAX_FILE_BYTES = 1024 * 1024
FILES = (
    "private/catalog_mutex.php",                 # New shared MySQL mutex
    "private/restore_journal.php",               # New fail-closed journal
    "private/snapshots.php",                     # Consistent snapshot/restore
    "catalogus-public/admin/kavel.php",          # Mutex for lot/photo writes
    "catalogus-public/admin/instellingen.php",   # Mutex for settings writes
    "catalogus-public/admin/login.php",          # Mutex for login_attempts
    "catalogus-public/admin/backup.php",         # Lock across safety+restore
    "private/bootstrap.php",                     # PHP maintenance gate LAST
)
REQUIRED_MARKERS = {
    "private/catalog_mutex.php": (
        b"CATALOG_DATA_LOCK_NAME", b"GET_LOCK", b"RELEASE_LOCK",
    ),
    "private/restore_journal.php": (
        b"restore_journal_pending", b"restore_journal_gate_web",
        b"restore_journal_begin", b"restore_journal_finish",
    ),
    "private/snapshots.php": (
        b"catalog_mutex.php", b"restore_journal.php",
        b"SET TRANSACTION ISOLATION LEVEL REPEATABLE READ",
        b"restore_journal_begin(", b"restore_journal_finish(",
    ),
    "catalogus-public/admin/kavel.php": (
        b"catalog_lock_for_request($pdo);", b"check_csrf();",
    ),
    "catalogus-public/admin/instellingen.php": (
        b"catalog_lock_for_request($pdo);", b"check_csrf();",
    ),
    "catalogus-public/admin/login.php": (
        b"catalog_lock_for_request($pdo);", b"check_csrf();",
    ),
    "catalogus-public/admin/backup.php": (
        b"catalog_data_lock($pdo);", b"snap_create($pdo)",
        b"snap_restore($pdo,$name)",
    ),
    "private/bootstrap.php": (
        b"restore_journal.php", b"restore_journal_gate_web();",
    ),
}
# No optional helpers or sensitive locations can enter the runtime manifest.
DENIED_PARTS = (
    "config.php", ".htaccess", "uploads/", "backups/", "snapshots/",
    "private/restore_finalize.php", "private/restore_precommit.php",
    "private/restore_diagnosis.php", "private/restore_mixed_diagnosis.php",
    "scripts/restore_",
)

def git(*args: str) -> bytes:
    result = subprocess.run(
        ["git", *args], cwd=ROOT, check=False, capture_output=True,
    )
    if result.returncode != 0:
        raise RuntimeError(f"Git verification failed: {args[0]}")
    return result.stdout

def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()

def verify_manifest_shape() -> None:
    if len(FILES) != 8 or len(set(FILES)) != len(FILES):
        raise RuntimeError("The release must have exactly eight distinct runtime paths.")
    if set(FILES) != set(REQUIRED_MARKERS):
        raise RuntimeError("Source marker list and runtime allowlist differ.")
    if FILES[:2] != (
        "private/catalog_mutex.php", "private/restore_journal.php"
    ) or FILES[-1] != "private/bootstrap.php":
        raise RuntimeError("Unsafe release ordering: helpers must be first, bootstrap last.")
    for name in FILES:
        if not name.endswith(".php") or name.startswith("/") or ".." in name.split("/"):
            raise RuntimeError(f"Unexpected path in allowlist: {name}")
        if any(denied in name for denied in DENIED_PARTS):
            raise RuntimeError(f"Forbidden production/data path: {name}")

def source_manifest(approved_sha: str) -> dict[str, object]:
    verify_manifest_shape()
    current_sha = git("rev-parse", "--verify", "HEAD").decode().strip().lower()
    if not re.fullmatch(r"[a-f0-9]{40}", approved_sha):
        raise RuntimeError("A complete 40-character release SHA is required.")
    if approved_sha != current_sha:
        raise RuntimeError("The requested release SHA is not the checked-out commit.")
    entries = []
    for position, name in enumerate(FILES, 1):
        path = ROOT / name
        if path.is_symlink() or not path.is_file():
            raise RuntimeError(f"Source missing, not a file, or symlink: {name}")
        content = path.read_bytes()
        if not content or len(content) > MAX_FILE_BYTES:
            raise RuntimeError(f"Source length is invalid: {name}")
        if git("show", f"{approved_sha}:{name}") != content:
            raise RuntimeError(f"Working source differs from approved commit: {name}")
        for marker in REQUIRED_MARKERS[name]:
            if marker not in content:
                raise RuntimeError(f"Required runtime integration not found: {name}")
        lint = subprocess.run(
            ["php", "-l", str(path)], cwd=ROOT, check=False, capture_output=True,
        )
        if lint.returncode != 0:
            raise RuntimeError(f"PHP syntax verification failed: {name}")
        entries.append({
            "order": position,
            "path": name,
            "bytes": len(content),
            "sha256": sha256(content),
            "php_lint": "pass",
        })
    joined = "\n".join(f"{item['path']} {item['sha256']}" for item in entries)
    return {
        "plan_format": "41lokeren-catalog-recovery-release-plan-v1",
        "release_sha": approved_sha,
        "purpose": "source-only-offline-readiness",
        "files": entries,
        "allowlist_count": len(entries),
        "source_manifest_sha256": sha256(joined.encode("ascii")),
        "upload_allowed": False,
        "production_state": "not_examined",
        "production_drift_checked": False,
        "host_key_and_remote_root_checked": False,
        "pending_restore_markers_checked": False,
        "production_backup_created": False,
        "external_data_backup_verified": False,
        "admin_quiet_window_confirmed": False,
        "release_approved": False,
        "deploy_approved": False,
        "next_gate": "manual security review, SFTP read-only preflight, approved backup/rollback and separate release/deploy consent",
    }

def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--release-sha", required=True)
    options = parser.parse_args()
    try:
        manifest = source_manifest(options.release_sha.lower())
    except (OSError, RuntimeError, ValueError) as exc:
        print(f"OFFLINE RELEASE PLAN STOP: {exc}", file=sys.stderr)
        return 1
    print(json.dumps(manifest, indent=2, sort_keys=True))
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
