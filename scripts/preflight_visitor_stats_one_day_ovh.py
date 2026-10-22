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
    # New installation: both destinations must be absent. Never overwrite an
    # unexpected regular file, symlink or any other SFTP object.
    targets = (
        "private/visitor_stats_archive_one_day_experimental.php",
        "catalogus-public/admin/statistieken.php",
    )
    statuses = {}
    for name in targets:
        path = posixpath.join(root, name)
        try:
            sftp.lstat(path)
        except FileNotFoundError:
            statuses[name] = "absent_as_expected"
        else:
            raise RuntimeError("STOP: installation target unexpectedly exists: " + name)
    return {
        "installation": "new_files_only",
        "target_statuses": statuses,
        "remote_root_validated": True,
        "hostkey_pinned": True,
        "database_schema_verified": False,
        "release_authorized": False,
        "upload_allowed": False,
    }

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
