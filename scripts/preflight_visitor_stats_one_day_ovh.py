#!/usr/bin/env python3
"""Read-only OVH SFTP preflight for approved one-day stats code. Never writes."""
import hashlib
import json
import os
import posixpath
import stat
import sys

from deploy_ovh_sftp import connect, remote_root, MAX_FILE_SIZE

EXISTING = "catalogus-public/admin/statistieken.php"
NEW = "private/visitor_stats_archive_one_day_experimental.php"
ADMIN_MARKER = b"visitor_stats_daily"

def check(sftp, root):
    # Verify familiar application markers; a mismatched root is fatal.
    for path, marker in (
        ("private/bootstrap.php", b"function admin_top"),
        ("catalogus-public/admin/index.php", b"Kaveloverzicht"),
    ):
        p = posixpath.join(root, path)
        info = sftp.lstat(p)
        if not stat.S_ISREG(info.st_mode) or info.st_size > MAX_FILE_SIZE:
            raise RuntimeError("Unexpected application marker file type: " + path)
        with sftp.open(p, "rb") as f:
            if marker not in f.read(MAX_FILE_SIZE + 1):
                raise RuntimeError("Unexpected remote application root")
    expected = os.environ.get("OVH_STATS_ADMIN_BASELINE_SHA256", "").strip().lower()
    if len(expected) != 64 or any(c not in "0123456789abcdef" for c in expected):
        raise RuntimeError("STOP: independently approved admin production baseline SHA256 missing")
    path = posixpath.join(root, EXISTING)
    info = sftp.lstat(path)
    if not stat.S_ISREG(info.st_mode) or not 0 < info.st_size <= MAX_FILE_SIZE:
        raise RuntimeError("STOP: existing admin PHP invalid or symlink")
    with sftp.open(path, "rb") as f:
        data = f.read(MAX_FILE_SIZE + 1)
    if ADMIN_MARKER not in data:
        raise RuntimeError("STOP: unexpected existing stats admin")
    actual = hashlib.sha256(data).hexdigest()
    if actual != expected:
        raise RuntimeError("STOP: remote stats admin drift; independent review required")
    new_path = posixpath.join(root, NEW)
    try:
        info = sftp.lstat(new_path)
    except FileNotFoundError:
        new_status = "absent_as_expected"
    else:
        raise RuntimeError("STOP: proposed new private source path already exists")
    return {"approved_baseline_sha256": actual, "new_private_path": new_status, "remote_root_validated": True, "drift_checked": True}

def main():
    root = remote_root()
    client = connect()  # Strict known_hosts key pinning; rejects unexpected hostkeys.
    try:
        with client.open_sftp() as sftp:
            result = check(sftp, root)
    finally:
        client.close()
    print(json.dumps({"mode": "read_only", "hostkey_pinned": True, "write_operations": False, **result}, sort_keys=True))

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("PREFLIGHT STOP: " + str(exc), file=sys.stderr)
        sys.exit(1)
