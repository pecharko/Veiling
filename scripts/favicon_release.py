#!/usr/bin/env python3
"""One-time two-file favicon release. Manual GitHub Actions only."""
from __future__ import annotations
import hashlib
import json
import os
from pathlib import Path
import posixpath
import stat
import subprocess

from deploy_ovh_sftp import connect, remote_root

ROOT = Path(__file__).resolve().parents[1]
FILES = ("catalogus-public/admin/login.php", "private/bootstrap.php")
BASELINE = "148754f5053896d20bb797152a020042ec749c4e"
BACKUP = ROOT / "deployment-backups" / "favicon"
MAX_BYTES = 1_000_000

def digest(data):
    return hashlib.sha256(data).hexdigest()

def sources():
    result = {}
    for name in FILES:
        f = ROOT / name
        if f.is_symlink() or not f.is_file():
            raise RuntimeError("Unsafe source: " + name)
        data = f.read_bytes()
        if not 0 < len(data) <= MAX_BYTES or b'favicon.png' not in data:
            raise RuntimeError("Unexpected PHP source: " + name)
        result[name] = data
    return result

def originals():
    return {name: subprocess.check_output(["git", "show", BASELINE + ":" + name], cwd=ROOT) for name in FILES}

def remote_read(sftp, path):
    item = sftp.lstat(path)
    if not stat.S_ISREG(item.st_mode) or not 0 < item.st_size <= MAX_BYTES:
        raise RuntimeError("Unsafe remote file: " + path)
    with sftp.open(path, "rb") as f:
        body = f.read(MAX_BYTES + 1)
    if len(body) != item.st_size:
        raise RuntimeError("Remote read changed or truncated: " + path)
    return body

def main(mode):
    new, old = sources(), originals()
    for name in FILES:
        if not old[name] or len(old[name]) > MAX_BYTES:
            raise RuntimeError("Unsafe original: " + name)
        print(name + ": new SHA256 " + digest(new[name]))
    if mode == "plan":
        print("Offline plan only, no SFTP connection.")
        return
    client = connect()
    try:
        with client.open_sftp() as sftp:
            base = remote_root()
            if not base.startswith("/") or ".." in base.split("/"):
                raise RuntimeError("Unsafe remote root")
            remote = {}
            for name in FILES:
                path = posixpath.join(base, name)
                remote[name] = remote_read(sftp, path)
                if remote[name] != old[name]:
                    raise RuntimeError("Production drift, refusing all writes: " + name)
            if mode == "backup":
                if BACKUP.exists():
                    raise RuntimeError("Backup already exists")
                BACKUP.mkdir(parents=True, mode=0o700)
                for name in FILES:
                    target = BACKUP / name
                    target.parent.mkdir(parents=True, exist_ok=True)
                    target.write_bytes(remote[name])
                (BACKUP / "manifest.json").write_text(json.dumps({
                    "baseline": BASELINE,
                    "sha256": {name: digest(body) for name, body in remote.items()}
                }, indent=2))
                print("Read-only remote code backup prepared, no upload")
                return
            raise RuntimeError("Upload requires separately reviewed deployment mode")
    finally:
        client.close()

if __name__ == "__main__":
    import sys
    if len(sys.argv) != 2 or sys.argv[1] not in ("plan", "backup"):
        raise SystemExit("Usage: favicon_release.py plan|backup")
    main(sys.argv[1])
