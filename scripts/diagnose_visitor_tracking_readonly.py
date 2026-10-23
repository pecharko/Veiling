#!/usr/bin/env python3
"""Read-only SFTP inspection, never expose config content or credentials."""
import hashlib
import posixpath
import re
import stat
from pathlib import Path
from deploy_ovh_sftp import connect, remote_root

FILES = ("private/visitor_stats.php", "catalogus-public/index.php", "catalogus-public/kavel.php")
MAX = 1048576

def read(sftp, root, name):
    path = posixpath.join(root, name)
    info = sftp.lstat(path)
    if not stat.S_ISREG(info.st_mode) or not 1 <= info.st_size <= MAX:
        raise RuntimeError("STOP unexpected file type/size: " + name)
    with sftp.open(path, "rb") as handle:
        data = handle.read(MAX + 1)
    post = sftp.lstat(path)
    if len(data) != info.st_size or post.st_size != info.st_size or post.st_mtime != info.st_mtime:
        raise RuntimeError("STOP file changed during read: " + name)
    return data

def inspect(sftp, root):
    bootstrap = read(sftp, root, "private/bootstrap.php")
    if b"function admin_top(" not in bootstrap:
        raise RuntimeError("STOP unexpected SFTP application root")
    for name in FILES:
        remote = read(sftp, root, name)
        local = Path(name).read_bytes()
        print("PATH", name, "present=true", "same_as_main=" + str(remote == local).lower(),
              "remote_sha256=" + hashlib.sha256(remote).hexdigest(), flush=True)
        if name == "private/visitor_stats.php":
            print("TRACKER_MARKERS", "tracking_entrypoint=" + str(b"function track_catalog_view(" in remote).lower(),
                  "insert_sql=" + str(b"INSERT INTO visitor_stats_daily" in remote).lower(),
                  "dnt_filter=" + str(b"HTTP_DNT" in remote).lower(), flush=True)
        else:
            print("PAGE_TRACKING_CALL", name, str(b"track_catalog_view(" in remote).lower(), flush=True)
    config = read(sftp, root, "private/config.php")
    # Boolean-only result; never print secret, config bytes, a hash of config or a matching substring.
    # Require a literal key and do not evaluate PHP.
    matches = re.findall(rb"""['"]analytics_secret['"]\s*=>\s*['"]([a-fA-F0-9]{64})['"]""", config)
    print("ANALYTICS_SECRET", "valid_literal_64hex=" + str(len(matches) == 1).lower(), flush=True)
    print("ADMIN_PAGES", "excluded_from_diagnostic_targets=true")
    print("DATABASE", "not_connected; schema and counts unverified")
    print("RESULT", "read_only_no_writes=true")

if __name__ == "__main__":
    client = connect()
    try:
        with client.open_sftp() as sftp:
            inspect(sftp, remote_root())
    finally:
        client.close()
