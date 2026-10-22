#!/usr/bin/env python3
"""Read-only discovery of live stats admin SHA-256; never uploads or mutates remote."""
import hashlib
import json
import posixpath
import stat
import sys

from deploy_ovh_sftp import connect, remote_root, MAX_FILE_SIZE

TARGET = "catalogus-public/admin/statistieken.php"
ANCHORS = (
    ("private/bootstrap.php", b"function admin_top"),
    ("catalogus-public/admin/index.php", b"Kaveloverzicht"),
)

def inspect(sftp, root):
    for name, marker in ANCHORS:
        info = sftp.lstat(posixpath.join(root, name))
        if not stat.S_ISREG(info.st_mode) or not 0 < info.st_size <= MAX_FILE_SIZE:
            raise RuntimeError("Unrecognized OVH application structure")
        with sftp.open(posixpath.join(root, name), "rb") as fh:
            data = fh.read(MAX_FILE_SIZE + 1)
        if len(data) > MAX_FILE_SIZE or marker not in data:
            raise RuntimeError("Unrecognized OVH application markers")
    location = posixpath.join(root, TARGET)
    info = sftp.lstat(location)
    if not stat.S_ISREG(info.st_mode) or not 0 < info.st_size <= MAX_FILE_SIZE:
        raise RuntimeError("Statistics admin missing, invalid or symbolic link")
    with sftp.open(location, "rb") as fh:
        data = fh.read(MAX_FILE_SIZE + 1)
    if not data or len(data) > MAX_FILE_SIZE or b"visitor_stats_daily" not in data:
        raise RuntimeError("Unexpected statistics admin content")
    return {"file": TARGET, "sha256": hashlib.sha256(data).hexdigest(),
            "bytes": len(data), "read_only": True, "baseline_approved": False,
            "warning": "Observation only: independently review before setting OVH_STATS_ADMIN_BASELINE_SHA256"}

def main():
    root = remote_root()
    client = connect()  # strict pinned known_hosts, unknown hosts rejected
    try:
        with client.open_sftp() as sftp:
            result = inspect(sftp, root)
    finally:
        client.close()
    print(json.dumps(result, sort_keys=True))

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("READ-ONLY BASELINE STOP: " + str(exc), file=sys.stderr)
        sys.exit(1)
