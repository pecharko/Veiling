#!/usr/bin/env python3
"""Read-only, exact-path snapshot reset-invalidation comparison on OVH."""
import hashlib
import json
import posixpath
from pathlib import Path
from scripts.shared_ovh_release import connect, read_remote

TARGET = "private/snapshots.php"

def digest(data):
    return hashlib.sha256(data).hexdigest()

def compare(source, remote):
    if not source or remote is None:
        return {"path": TARGET, "source_sha256": digest(source), "remote_sha256": "ABSENT" if remote is None else digest(remote), "match": False}
    return {"path": TARGET, "source_sha256": digest(source), "remote_sha256": digest(remote), "match": source == remote}

def main():
    src = Path(TARGET)
    if src.is_symlink() or not src.is_file():
        raise RuntimeError("Unsafe source")
    source = src.read_bytes()
    client, root = connect()
    try:
        with client.open_sftp() as sftp:
            sentinel = read_remote(sftp, posixpath.join(root, "catalogus-public/admin/index.php"))
            if sentinel is None or b"dashboard-table" not in sentinel:
                raise RuntimeError("Incorrect remote root")
            remote = read_remote(sftp, posixpath.join(root, TARGET))
    finally:
        client.close()
    print(json.dumps(compare(source, remote), sort_keys=True))

if __name__ == "__main__":
    main()
