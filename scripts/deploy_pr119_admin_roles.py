#!/usr/bin/env python3
"""Manual PR119 four-file deployment; no implicit production writes or rollback."""
import argparse
import hashlib
import os
import posixpath
import stat
import sys
from pathlib import Path
from deploy_ovh_sftp import connect, remote_root

FILES = (
    "catalogus-public/admin/gebruikers.php",
    "catalogus-public/admin/login.php",
    "private/snapshots.php",
    "private/bootstrap.php",
)
EXISTING = FILES[1:]
MARKERS = {
    "catalogus-public/admin/login.php": b"password_verify",
    "private/snapshots.php": b"SNAP_TABLES",
    "private/bootstrap.php": b"function admin_top",
}
CONFIRM = "DEPLOY PR119 ADMIN ROLES WITHOUT ROLLBACK"
MAX_BYTES = 2 * 1024 * 1024

def sha(data):
    return hashlib.sha256(data).hexdigest()

def sources():
    out = {}
    for name in FILES:
        path = Path(name)
        if path.is_symlink() or not path.is_file():
            raise RuntimeError("Missing or symlinked source: " + name)
        data = path.read_bytes()
        if not 0 < len(data) <= MAX_BYTES:
            raise RuntimeError("Invalid source size: " + name)
        out[name] = data
    if b"require_administrator();" not in out[FILES[0]]:
        raise RuntimeError("Missing administrator guard")
    if b"role FROM admins" not in out[FILES[-1]]:
        raise RuntimeError("Missing role authorization")
    return out

def inspect(sftp, root):
    for dirname in ("private", "catalogus-public", "catalogus-public/admin"):
        st = sftp.lstat(posixpath.join(root, dirname))
        if not stat.S_ISDIR(st.st_mode):
            raise RuntimeError("Unexpected remote directory: " + dirname)
    out = {}
    for name in FILES:
        path = posixpath.join(root, name)
        try:
            st = sftp.lstat(path)
        except FileNotFoundError:
            if name == FILES[0]:
                out[name] = None
                continue
            raise RuntimeError("Missing existing file: " + name)
        if not stat.S_ISREG(st.st_mode) or not 0 < st.st_size <= MAX_BYTES:
            raise RuntimeError("Invalid remote file: " + name)
        with sftp.open(path, "rb") as fp:
            data = fp.read(MAX_BYTES + 1)
        if not data or len(data) > MAX_BYTES:
            raise RuntimeError("Invalid remote bytes: " + name)
        if name in MARKERS and MARKERS[name] not in data:
            raise RuntimeError("Remote root identity check failed: " + name)
        out[name] = data
    return out

def main():
    p = argparse.ArgumentParser()
    p.add_argument("mode", choices=("plan", "preflight", "deploy"))
    args = p.parse_args()
    desired = sources()
    for path, content in desired.items():
        print("SOURCE", path, sha(content))
    if args.mode == "plan":
        print("OFFLINE ONLY; no network or production changes")
        return
    client = connect()
    try:
        with client.open_sftp() as sftp:
            root = remote_root()
            current = inspect(sftp, root)
            for path, content in current.items():
                print("REMOTE", path, "ABSENT" if content is None else sha(content))
            if args.mode == "preflight":
                print("READ ONLY; no changes")
                return
            if os.getenv("PR119_CONFIRMATION") != CONFIRM:
                raise RuntimeError("Missing distinct final authorization")
            if os.getenv("PR119_DB_READY") != "ROLE_MIGRATION_VERIFIED":
                raise RuntimeError("Missing separate database migration verification attestation")
            expected = {
                FILES[0]: "ABSENT",
                FILES[1]: os.getenv("PR119_EXPECT_LOGIN", ""),
                FILES[2]: os.getenv("PR119_EXPECT_SNAPSHOTS", ""),
                FILES[3]: os.getenv("PR119_EXPECT_BOOTSTRAP", ""),
            }
            for path, data in current.items():
                actual = "ABSENT" if data is None else sha(data)
                if actual != expected[path]:
                    raise RuntimeError("STOP: drift or incomplete expected hash: " + path)
            # Existing files first; authorization bootstrap last.
            for path in (FILES[1], FILES[2], FILES[0], FILES[3]):
                target = posixpath.join(root, path)
                data = desired[path]
                mode = "wxb" if path == FILES[0] else "wb"
                with sftp.open(target, mode) as fp:
                    fp.write(data)
                    fp.flush()
                with sftp.open(target, "rb") as fp:
                    actual = fp.read(MAX_BYTES + 1)
                if sha(actual) != sha(data):
                    raise RuntimeError("STOP: remote verification failed, manual repair necessary: " + path)
                print("VERIFIED", path, sha(actual))
            print("UPLOAD COMPLETE; follow with read-only HTTP and manual role acceptance")
    finally:
        client.close()

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("STOP:", exc, file=sys.stderr)
        sys.exit(1)
