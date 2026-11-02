#!/usr/bin/env python3
"""Read-only SFTP hash preflight for a user-supplied, restricted path list."""
import hashlib
import json
import os
from pathlib import Path
import posixpath
import re
import stat
import subprocess
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
                or any(not re.fullmatch(r"[A-Za-z0-9_.-]+", x) for x in parts)):
            raise ValueError("Invalid preflight target")
    return paths


def sha(data):
    return hashlib.sha256(data).hexdigest()


def source_bytes(name, source_sha="", root=Path(".")):
    """Read regular candidate blobs without checkout, imports, hooks or execution."""
    if validate_paths(name) != [name]:
        raise ValueError("Invalid source path")
    if source_sha:
        if not re.fullmatch(r"[a-f0-9]{40}", source_sha):
            raise ValueError("Require immutable candidate SHA")
        def git(*args):
            return subprocess.run(["git", *args], cwd=root, check=True,
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout
        if git("rev-parse", "--verify", source_sha + "^{commit}").strip().decode() != source_sha:
            raise ValueError("Candidate must be an immutable commit")
        row = git("ls-tree", "-z", "--full-tree", source_sha, "--", name)
        if row.count(b"\0") != 1:
            raise ValueError("Missing or ambiguous candidate file")
        meta, path = row[:-1].split(b"\t", 1)
        mode, kind, blob = meta.split()
        if (path != name.encode() or mode not in (b"100644", b"100755")
                or kind != b"blob" or not re.fullmatch(rb"[a-f0-9]{40}", blob)):
            raise ValueError("Candidate source must be a regular Git blob")
        size = int(git("cat-file", "-s", blob.decode()).strip())
        if not 0 < size <= 8 * 1024 * 1024:
            raise ValueError("Missing or large candidate source")
        raw = git("cat-file", "blob", blob.decode())
        if len(raw) != size:
            raise ValueError("Candidate Git blob size mismatch")
        return raw
    src = root / name
    if (not src.is_file() or src.is_symlink()
            or not src.resolve().is_relative_to(root.resolve())
            or not 0 < src.stat().st_size <= 8 * 1024 * 1024):
        raise ValueError("Missing, unsafe or large source")
    return src.read_bytes()


def main():
    paths = validate_paths(os.environ["RELEASE_FILES"])
    source_sha = os.environ.get("SOURCE_SHA", "")
    # Validate EVERY source before opening a production connection.
    sources = {name: source_bytes(name, source_sha) for name in paths}
    manifest = {"schema": 1, "files": []}
    if source_sha:
        manifest["head_sha"] = source_sha
    client = connect()
    try:
        with client.open_sftp() as sftp:
            for name in paths:
                source = sources[name]
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
