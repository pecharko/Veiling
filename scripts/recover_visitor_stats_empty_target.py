#!/usr/bin/env python3
"""One-shot recovery: remove ONLY verified empty failed-upload target; install two files."""
import hashlib
import os
import posixpath
import stat
import sys
from deploy_ovh_sftp import connect, remote_root
from deploy_visitor_stats_two_files import FILES, MARKERS, sources, main as deploy_main

EMPTY = FILES[0]
OTHER = FILES[1]
EMPTY_SHA = hashlib.sha256(b"").hexdigest()
CONFIRM = "RECOVER EMPTY VISITOR STATS AND DEPLOY TWO FILES"

def remote_checks(sftp, root):
    from deploy_visitor_stats_two_files import MAX_FILE_SIZE
    for name, marker in MARKERS:
        info = sftp.lstat(posixpath.join(root, name))
        if not stat.S_ISREG(info.st_mode) or info.st_size > MAX_FILE_SIZE:
            raise RuntimeError("Unexpected application root")
        with sftp.open(posixpath.join(root, name), "rb") as handle:
            if marker not in handle.read(MAX_FILE_SIZE + 1):
                raise RuntimeError("Application root marker mismatch")
    first = posixpath.join(root, EMPTY)
    info = sftp.lstat(first)
    if not stat.S_ISREG(info.st_mode) or info.st_size != 0:
        raise RuntimeError("STOP: recovery target not exactly empty regular file")
    with sftp.open(first, "rb") as handle:
        contents = handle.read(1)
    if contents or hashlib.sha256(contents).hexdigest() != EMPTY_SHA:
        raise RuntimeError("STOP: recovery file data mismatch")
    try:
        sftp.lstat(posixpath.join(root, OTHER))
    except FileNotFoundError:
        return first
    raise RuntimeError("STOP: second target unexpectedly exists")

def main():
    # Enforce exact source allowlist and valid non-empty reference files before touching server.
    if tuple(FILES) != ("private/visitor_stats_archive_one_day_experimental.php",
                        "catalogus-public/admin/statistieken.php"):
        raise RuntimeError("Unexpected source allowlist")
    sources()
    if os.environ.get("VISITOR_RECOVERY_CONFIRMATION") != CONFIRM:
        raise RuntimeError("Missing distinct recovery authorization")
    client = connect()
    try:
        with client.open_sftp() as sftp:
            root = remote_root()
            first = remote_checks(sftp, root)
            # No other remove or overwrite in recovery. Fail closed for any drift.
            sftp.remove(first)
            print("Verified empty zero-byte target removed: " + EMPTY)
    finally:
        client.close()
    # Existing reviewed 'wx' exclusive-create installer rechecks root and BOTH target paths.
    # A failure after deletion or any partial upload is NOT retried or rolled back.
    os.environ["VISITOR_RELEASE_CONFIRMATION"] = "DEPLOY VISITOR STATS TWO NEW FILES"
    deploy_main("deploy")

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("RECOVERY STOP: " + str(exc), file=sys.stderr)
        sys.exit(1)
