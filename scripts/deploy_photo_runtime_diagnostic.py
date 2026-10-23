#!/usr/bin/env python3
"""Manual single-new-file diagnostic release; default offline plan, no implicit writes."""
from __future__ import annotations
import argparse, hashlib, os, posixpath, stat, sys
from pathlib import Path
from deploy_ovh_sftp import connect, remote_root

SOURCE_COMMIT = "9a1d3072dae88952926d80dc0adfe197e04d0084"
TARGET = "catalogus-public/admin/foto-capaciteit.php"
APPROVAL = "DEPLOY PHOTO CAPACITY DIAGNOSTIC ONLY"
EXPECTED_SHA256 = "fc273cb10013baf71df9dd5364c404ff5f361a1109cdef9277093efe8027c6cf"

def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()

def payload() -> bytes:
    path = Path(TARGET)
    if path.is_symlink() or not path.is_file():
        raise RuntimeError("Source is missing or symlink")
    data = path.read_bytes()
    if not 200 < len(data) < 20000 or b"require_auth();" not in data or b"memory_limit" not in data:
        raise RuntimeError("Unexpected diagnostic payload")
    if sha256(data) != EXPECTED_SHA256:
        raise RuntimeError("STOP: diagnostic source SHA-256 differs from approved source")
    return data

def inspect(sftp, root):
    # Root must be configured, pinned host key is verified by connect().
    admin_dir = posixpath.join(root, "catalogus-public/admin")
    parent = sftp.lstat(admin_dir)
    if not stat.S_ISDIR(parent.st_mode):
        raise RuntimeError("Unexpected admin directory")
    bootstrap = sftp.lstat(posixpath.join(root, "private/bootstrap.php"))
    if not stat.S_ISREG(bootstrap.st_mode):
        raise RuntimeError("Wrong remote root / bootstrap")
    path = posixpath.join(root, TARGET)
    try:
        sftp.lstat(path)
    except FileNotFoundError:
        return path
    raise RuntimeError("STOP: diagnostic target already exists; never overwrite")

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("mode", choices=["plan", "preflight", "deploy"])
    args = ap.parse_args()
    data = payload()
    print("allowlist:", TARGET)
    print("source SHA256:", sha256(data))
    if args.mode == "plan":
        print("OFFLINE PASS; no remote connection, no file changes")
        return
    client = connect()
    try:
        with client.open_sftp() as sftp:
            target = inspect(sftp, remote_root())
            print("PASS: expected target absent and admin root verified")
            if args.mode == "preflight":
                print("Read-only SFTP preflight; no writes")
                return
            if os.environ.get("DIAGNOSTIC_FINAL_AUTH") != APPROVAL:
                raise RuntimeError("STOP: distinct final production authorization missing")
            # Exclusive creation prevents overwriting an existing production file.
            # Simple release: never attempt rollback or deletion on failure.
            with sftp.open(target, "wx") as remote:
                remote.write(data)
                remote.flush()
            info = sftp.lstat(target)
            if not stat.S_ISREG(info.st_mode) or info.st_size != len(data):
                raise RuntimeError("Remote size/type mismatch; manual recovery may be required")
            with sftp.open(target, "rb") as remote:
                actual = remote.read(len(data) + 1)
            if actual != data:
                raise RuntimeError("SHA-256 mismatch after deployment; manual recovery may be required")
            print("VERIFIED uploaded SHA256:", sha256(actual))
    finally:
        client.close()

if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print("STOP:", error, file=sys.stderr)
        sys.exit(1)
