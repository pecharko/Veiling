#!/usr/bin/env python3
"""Two-file OVH high-resolution photo release, explicitly without rollback."""
import hashlib
import os
import posixpath
import stat
import sys
from pathlib import Path
from scripts.deploy_ovh_sftp import connect, remote_root

FILES = (
    "private/images.php",
    "catalogus-public/admin/kavel.php",
)
EXPECTED_REMOTE = {
    "private/images.php": "09746e3e3fe337e9bf84e479b3a9426099c9e9d95772b27dce45d1a907777f58",
    "catalogus-public/admin/kavel.php": "b7f8d2669f61f64b949b3f3ba4400ce5f157a9faf6c71964db00e0f8db90051a",
}
EXPECTED_SOURCE = {
    "private/images.php": "fafea6f340312ea811b39140281277cef766f80a008517f32138eb1638334b9b",
    "catalogus-public/admin/kavel.php": "3e8e75282b21322dca383d099fa09cf7295985784017c0489c365f80c6d9a443",
}
MAX_BYTES = 1048576

def sha(data):
    return hashlib.sha256(data).hexdigest()

def sources():
    result = {}
    for name in FILES:
        path = Path(name)
        if path.is_symlink() or not path.is_file():
            raise RuntimeError("STOP invalid local path " + name)
        data = path.read_bytes()
        if not 1 <= len(data) <= MAX_BYTES or sha(data) != EXPECTED_SOURCE[name]:
            raise RuntimeError("STOP unexpected source checksum " + name)
        result[name] = data
        print("source", name, sha(data), flush=True)
    return result

def remote_read(sftp, root, name):
    target = posixpath.join(root, name)
    meta = sftp.lstat(target)
    if not stat.S_ISREG(meta.st_mode) or not 1 <= meta.st_size <= MAX_BYTES:
        raise RuntimeError("STOP unexpected remote target " + name)
    with sftp.open(target, "rb") as fh:
        data = fh.read(MAX_BYTES + 1)
    again = sftp.lstat(target)
    if len(data) != meta.st_size or again.st_size != meta.st_size or again.st_mtime != meta.st_mtime:
        raise RuntimeError("STOP changing remote target " + name)
    return data

def main(mode):
    local = sources()
    if mode == "plan":
        print("OFFLINE PLAN ONLY: profile=simple/no rollback; exactly two files")
        return
    client = connect()
    try:
        with client.open_sftp() as sftp:
            root = remote_root()
            existing = {name: remote_read(sftp, root, name) for name in FILES}
            for name in FILES:
                actual = sha(existing[name])
                if actual != EXPECTED_REMOTE[name]:
                    raise RuntimeError("STOP production drift " + name + " " + actual)
                print("remote preflight", name, actual, flush=True)
            if mode == "preflight":
                print("READ ONLY: both targets match approved old checksums")
                return
            if os.environ.get("PHOTO_TWOFILE_FINAL_AUTH") != "DEPLOY HIGHRES PHOTO TWO FILES NO ROLLBACK":
                raise RuntimeError("STOP final deploy authorization absent")
            # All drift checks complete before any write. No files or data are deleted.
            for name in FILES:
                target = posixpath.join(root, name)
                print("UPLOAD START", name, flush=True)
                with sftp.open(target, "wb") as fh:
                    fh.write(local[name])
                    fh.flush()
                if sha(remote_read(sftp, root, name)) != EXPECTED_SOURCE[name]:
                    raise RuntimeError("STOP UPLOAD FAILED; partial deployment possible: " + name)
                print("UPLOAD VERIFIED", name, EXPECTED_SOURCE[name], flush=True)
            print("TWO-FILE RELEASE VERIFIED; NO ROLLBACK ARTIFACT")
    finally:
        client.close()

if __name__ == "__main__":
    if len(sys.argv) != 2 or sys.argv[1] not in ("plan", "preflight", "deploy"):
        raise SystemExit("usage: twofile_photo_release.py plan|preflight|deploy")
    try:
        main(sys.argv[1])
    except Exception as exc:
        print(str(exc), file=sys.stderr, flush=True)
        raise SystemExit(1)
