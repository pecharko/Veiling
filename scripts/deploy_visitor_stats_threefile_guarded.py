#!/usr/bin/env python3
"""Explicitly gated, three-file analytics code release. No database operations."""
import argparse
import hashlib
import os
import posixpath
import stat
from pathlib import Path
from deploy_ovh_sftp import connect, remote_root
from plan_visitor_stats_three_file import FILES, KNOWN_REMOTE, plan

MAX = 1048576
CONFIRM = "DEPLOY THREE VISITOR TRACKING NO ROLLBACK"

def digest(data):
    return hashlib.sha256(data).hexdigest()

def read(sftp, root, name, optional=False):
    target = posixpath.join(root, name)
    try:
        meta = sftp.lstat(target)
    except FileNotFoundError:
        if optional:
            return None
        raise
    if not stat.S_ISREG(meta.st_mode) or not 1 <= meta.st_size <= MAX:
        raise RuntimeError("STOP unsafe target " + name)
    with sftp.open(target, "rb") as file:
        value = file.read(MAX+1)
    again = sftp.lstat(target)
    if len(value) != meta.st_size or (again.st_size,again.st_mtime) != (meta.st_size,meta.st_mtime):
        raise RuntimeError("STOP changing target " + name)
    return value

def verify(sftp, root):
    bootstrap = read(sftp, root, "private/bootstrap.php")
    if b"function admin_top(" not in bootstrap:
        raise RuntimeError("STOP wrong application root")
    for name in FILES:
        data = read(sftp, root, name, optional=name == FILES[0])
        expected = KNOWN_REMOTE[name]
        if expected is None:
            if data is not None:
                raise RuntimeError("STOP unexpected existing target " + name)
            print("EXPECTED ABSENT", name, flush=True)
        else:
            if data is None or digest(data) != expected:
                raise RuntimeError("STOP production drift " + name)
            print("REMOTE VERIFIED", name, digest(data), flush=True)

def run(mode):
    manifest = plan()
    expected = {x["path"]:x["source_sha256"] for x in manifest["sources"]}
    if tuple(expected) != FILES:
        raise RuntimeError("STOP changed allowlist")
    sources={}
    for name in FILES:
        p=Path(__file__).resolve().parents[1]/name
        if not p.is_file() or p.is_symlink():
            raise RuntimeError("STOP source path " + name)
        data=p.read_bytes()
        if not 1 <= len(data) <= MAX or digest(data) != expected[name]:
            raise RuntimeError("STOP source checksum " + name)
        sources[name]=data
        print("SOURCE VERIFIED", name, digest(data), flush=True)
    if mode=="plan":
        print("OFFLINE PLAN; NO REMOTE CHANGES")
        return
    client=connect()
    try:
        with client.open_sftp() as sftp:
            root=remote_root()
            verify(sftp,root)
            if mode=="preflight":
                print("READ ONLY; NO REMOTE CHANGES")
                return
            if os.environ.get("THREEFILE_FINAL_AUTH") != CONFIRM:
                raise RuntimeError("STOP final deploy authorization absent")
            verify(sftp,root)
            for name in FILES:
                target=posixpath.join(root,name)
                if name==FILES[0]:
                    if read(sftp,root,name,optional=True) is not None:
                        raise RuntimeError("STOP unexpected helper target")
                    mode_sftp="wxb"
                else:
                    if digest(read(sftp,root,name))!=KNOWN_REMOTE[name]:
                        raise RuntimeError("STOP target drift immediately before upload")
                    mode_sftp="wb"
                print("UPLOAD START",name,flush=True)
                with sftp.open(target,mode_sftp) as file:
                    file.write(sources[name])
                    file.flush()
                if digest(read(sftp,root,name))!=expected[name]:
                    raise RuntimeError("STOP partial deployment possible at "+name)
                print("UPLOAD VERIFIED",name,expected[name],flush=True)
            print("THREE FILES VERIFIED; NO ROLLBACK ARTIFACT")
    finally:
        client.close()

if __name__=="__main__":
    parser=argparse.ArgumentParser()
    parser.add_argument("mode",choices=("plan","preflight","deploy"))
    args=parser.parse_args()
    try:
        run(args.mode)
    except Exception as error:
        raise SystemExit(str(error))
