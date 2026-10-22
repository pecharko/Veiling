#!/usr/bin/env python3
"""Read-only inventory of two visitor statistics release targets on OVH."""
import hashlib
import json
import posixpath
import stat
from pathlib import Path
from deploy_ovh_sftp import connect, remote_root, MAX_FILE_SIZE

ROOT = Path(__file__).resolve().parents[1]
FILES = (
    "private/visitor_stats_archive_one_day_experimental.php",
    "catalogus-public/admin/statistieken.php",
)
MARKERS = (
    ("private/bootstrap.php", b"function admin_top"),
    ("catalogus-public/admin/index.php", b"Kaveloverzicht"),
)

def inspect(sftp, root):
    # Confirm this is the expected production tree before interpreting results.
    for name, marker in MARKERS:
        path = posixpath.join(root, name)
        info = sftp.lstat(path)
        if not stat.S_ISREG(info.st_mode) or info.st_size > MAX_FILE_SIZE:
            raise RuntimeError("Remote application-root marker invalid")
        with sftp.open(path, "rb") as handle:
            if marker not in handle.read(MAX_FILE_SIZE + 1):
                raise RuntimeError("Remote application-root marker mismatch")
    results = []
    for name in FILES:
        local = ROOT / name
        if not local.is_file() or local.is_symlink():
            raise RuntimeError("Missing or symlink local reference")
        reference = local.read_bytes()
        if not reference or len(reference) > MAX_FILE_SIZE:
            raise RuntimeError("Invalid local reference size")
        expected = hashlib.sha256(reference).hexdigest()
        path = posixpath.join(root, name)
        try:
            info = sftp.lstat(path)
        except FileNotFoundError:
            results.append(dict(path=name, exists=False, type="absent", bytes=None,
                                local_bytes=len(reference), sha256=None, expected_sha256=expected, matches=False))
            continue
        kind = ("regular" if stat.S_ISREG(info.st_mode) else
                "symlink" if stat.S_ISLNK(info.st_mode) else "other")
        actual = None
        if kind == "regular" and info.st_size <= MAX_FILE_SIZE:
            with sftp.open(path, "rb") as handle:
                raw = handle.read(MAX_FILE_SIZE + 1)
            if len(raw) <= MAX_FILE_SIZE:
                actual = hashlib.sha256(raw).hexdigest()
        results.append(dict(path=name, exists=True, type=kind, bytes=info.st_size,
                            local_bytes=len(reference), sha256=actual,
                            expected_sha256=expected, matches=(actual == expected)))
    return results

def main():
    client = connect()
    try:
        with client.open_sftp() as sftp:
            results = inspect(sftp, remote_root())
    finally:
        client.close()
    print(json.dumps({"mode":"read_only", "source_commit":"9af7316fee95dab005fd8ca565f1f399f5c14918",
                      "targets":results, "write_operations":False}, indent=2))
    # Diagnostic succeeds even when targets are absent or mismatched; the data guides recovery.
    # Refuse implicit recovery/installation on any result.

if __name__ == "__main__":
    main()
