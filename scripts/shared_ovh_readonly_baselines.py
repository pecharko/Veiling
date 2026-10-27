#!/usr/bin/env python3
"""Read-only SFTP hash preflight for a user-supplied, restricted path list."""
import hashlib
import json
import os
from pathlib import Path
import posixpath
import re
import stat
from scripts.deploy_ovh_sftp import connect, remote_root

EXT = (".php", ".css", ".js", ".html", ".svg", ".webp", ".png", ".jpg", ".jpeg")
DENY = ("private/visitor_stats_daily_cron.php",)
def validate_paths(value):
    paths = [p.strip() for p in value.split(",")]
    if not 1 <= len(paths) <= 25 or len(set(paths)) != len(paths):
        raise ValueError("Invalid path list")
    for p in paths:
        parts = p.split("/")
        if (not p.startswith(("private/", "catalogus-public/"))
                or not p.endswith(EXT)
                or any(not x or x.startswith(".") for x in parts)
                or any(x in ("..", ".") for x in parts)
                or p in DENY or p.startswith(("private/config", "private/snapshots"))
                or "\\" in p):
            raise ValueError("Invalid preflight target")
    return paths


def sha(data):
    return hashlib.sha256(data).hexdigest()


def main():
    paths = validate_paths(os.environ["RELEASE_FILES"])
    root = Path(".")
    manifest = {"schema": 1, "files": []}
    client = connect()
    try:
        with client.open_sftp() as sftp:
            for name in paths:
                src = root / name
                if not src.is_file() or src.is_symlink() or src.stat().st_size > 8 * 1024 * 1024:
                    raise ValueError("Missing or large source")
                source = src.read_bytes()
                target = posixpath.join(remote_root(), name)
                try:
                    info = sftp.lstat(target)
                except OSError as exc:
                    if getattr(exc, "errno", None) == 2:
                        remote = "ABSENT"
                    else:
                        raise
                else:
                    if not stat.S_ISREG(info.st_mode) or info.st_size > 8 * 1024 * 1024:
                        raise RuntimeError("Unsafe or large remote file")
                    with sftp.open(target, "rb") as f:
                        raw = f.read(8 * 1024 * 1024 + 1)
                    if len(raw) != info.st_size:
                        raise RuntimeError("Remote file changed while reading")
                    remote = sha(raw)
                manifest["files"].append({"path": name,
                    "source_sha256": sha(source), "remote_sha256": remote})
                print("READ ONLY", name, "source", sha(source), "remote", remote)
    finally:
        client.close()
    with open("shared-ovh-readonly-baselines.json", "w", encoding="utf-8") as out:
        json.dump(manifest, out, indent=2)
        out.write("\n")


if __name__ == "__main__":
    main()
