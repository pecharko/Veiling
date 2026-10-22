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

def stat_expected_file(sftp, root, name):
    """Only disclose repository-relative allowlisted path, never OVH account root."""
    try:
        return sftp.lstat(posixpath.join(root, name))
    except FileNotFoundError as exc:
        raise RuntimeError("Required remote path missing: " + name) from exc


def inspect(sftp, root):
    for name, marker in ANCHORS:
        info = stat_expected_file(sftp, root, name)
        if not stat.S_ISREG(info.st_mode) or not 0 < info.st_size <= MAX_FILE_SIZE:
            raise RuntimeError("Unrecognized OVH application structure")
        with sftp.open(posixpath.join(root, name), "rb") as fh:
            data = fh.read(MAX_FILE_SIZE + 1)
        if len(data) > MAX_FILE_SIZE or marker not in data:
            raise RuntimeError("Unrecognized OVH application markers")
    location = posixpath.join(root, TARGET)
    try:
        info = sftp.lstat(location)
    except FileNotFoundError:
        # Only inspect the verified admin directory, never crawl the SFTP account.
        parent = posixpath.join(root, "catalogus-public/admin")
        entries = sftp.listdir_attr(parent)
        candidates = sorted(
            item.filename for item in entries
            if item.filename not in (".", "..")
            and item.filename.endswith(".php")
            and ("statist" in item.filename.lower() or "analytic" in item.filename.lower())
            and stat.S_ISREG(item.st_mode)
        )
        return {
            "expected_file": TARGET, "expected_status": "absent",
            "candidate_admin_files": candidates, "observed": "directory_names_only",
            "installation_classification": "new_file_at_expected_path",
            "baseline_sha256": None, "baseline_approved": False,
            "read_only": True, "release_authorized": False,
            "warning": "Do not treat absence as permission to upload; inspect candidate files and dependencies first"
        }
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
