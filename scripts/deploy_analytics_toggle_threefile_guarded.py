#!/usr/bin/env python3
"""Guarded three-file analytics-toggle release. No cleanup, schema or backups."""
import argparse
import hashlib
import os
import posixpath
import stat
import subprocess
from pathlib import Path
from deploy_ovh_sftp import connect, remote_root

FILES = ("private/analytics_control.php", "private/visitor_stats.php",
         "catalogus-public/admin/statistieken.php")
BASELINE = "52c30031dae0f186e71714d6a3414fa17101bc8c"
CONFIRM = "DEPLOY ANALYTICS TOGGLE THREE FILES NO ROLLBACK"
MAX = 1048576

def digest(data):
    return hashlib.sha256(data).hexdigest()

def current_sources():
    root = Path(__file__).resolve().parents[1]
    result = {}
    for name in FILES:
        p = root / name
        if not p.is_file() or p.is_symlink():
            raise RuntimeError("STOP invalid source " + name)
        data = p.read_bytes()
        if not (1 <= len(data) <= MAX):
            raise RuntimeError("STOP invalid source size " + name)
        result[name] = data
        print("SOURCE VERIFIED", name, digest(data), flush=True)
    return result

def historical_sources():
    original = {}
    for name in FILES[1:]:
        data = subprocess.check_output(["git", "show", BASELINE + ":" + name])
        original[name] = digest(data)
        print("EXPECTED REMOTE BASELINE", name, original[name], flush=True)
    return original

def remote_read(sftp, root, name, optional=False):
    target = posixpath.join(root, name)
    try:
        info = sftp.lstat(target)
    except FileNotFoundError:
        if optional:
            return None
        raise
    if not stat.S_ISREG(info.st_mode) or not (1 <= info.st_size <= MAX):
        raise RuntimeError("STOP unsafe production path " + name)
    with sftp.open(target, "rb") as stream:
        data = stream.read(MAX + 1)
    again = sftp.lstat(target)
    if len(data) != info.st_size or (again.st_size, again.st_mtime) != (info.st_size, info.st_mtime):
        raise RuntimeError("STOP changing production file " + name)
    return data

def verify(sftp, root, old):
    if b"function admin_top(" not in remote_read(sftp, root, "private/bootstrap.php"):
        raise RuntimeError("STOP unexpected production root")
    if remote_read(sftp, root, FILES[0], optional=True) is not None:
        raise RuntimeError("STOP new analytics helper already exists")
    print("EXPECTED ABSENT", FILES[0], flush=True)
    for name in FILES[1:]:
        actual = digest(remote_read(sftp, root, name))
        if actual != old[name]:
            raise RuntimeError("STOP production drift " + name)
        print("REMOTE VERIFIED", name, actual, flush=True)

def run(mode):
    source = current_sources()
    previous = historical_sources()
    if mode == "plan":
        print("OFFLINE PLAN; NO REMOTE CHANGES")
        return
    ssh = connect()
    try:
        with ssh.open_sftp() as sftp:
            root = remote_root()
            verify(sftp, root, previous)
            if mode == "preflight":
                print("READ ONLY; NO REMOTE CHANGES")
                return
            if os.environ.get("ANALYTICS_TOGGLE_FINAL_AUTH") != CONFIRM:
                raise RuntimeError("STOP missing separate final authorization")
            verify(sftp, root, previous)
            for idx, name in enumerate(FILES):
                target = posixpath.join(root, name)
                if idx == 0:
                    if remote_read(sftp, root, name, optional=True) is not None:
                        raise RuntimeError("STOP helper appeared during deploy")
                    open_mode = "wxb"
                else:
                    if digest(remote_read(sftp, root, name)) != previous[name]:
                        raise RuntimeError("STOP production drift before upload " + name)
                    open_mode = "wb"
                print("UPLOAD START", name, flush=True)
                with sftp.open(target, open_mode) as stream:
                    stream.write(source[name])
                    stream.flush()
                if digest(remote_read(sftp, root, name)) != digest(source[name]):
                    raise RuntimeError("STOP partial deployment possible at " + name)
                print("UPLOAD VERIFIED", name, digest(source[name]), flush=True)
            print("THREE FILES VERIFIED; SIMPLE NO-ROLLBACK RELEASE")
    finally:
        ssh.close()

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=("plan", "preflight", "deploy"))
    args = parser.parse_args()
    try:
        run(args.mode)
    except Exception as exc:
        raise SystemExit(str(exc))
