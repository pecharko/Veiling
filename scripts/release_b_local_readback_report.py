#!/usr/bin/env python3
"""Offline, fail-closed OVH download vs immutable Git tree comparison.

This is not independent remote readback, historical deployment attestation,
proof of download origin, or permission to close release administration.
Never connects to OVH, runs GitHub Actions, or prints file contents.
"""
import argparse
import hashlib
import json
import re
import stat
import subprocess
from pathlib import Path

COMMIT = re.compile(r"[0-9a-f]{40}")
TARGETS = (
    "catalogus-public/admin/admin.css",
    "catalogus-public/admin/verkoper.php",
    "catalogus-public/admin/verkopers.php",
    "private/lot_sellers.php",
)
MAX_BYTES = 8 * 1024 * 1024


def hash_download(root, relative):
    """Hash only the four known regular files with no symlinks in any component."""
    path = root
    for part in relative.split("/"):
        path = path / part
        if path.is_symlink():
            raise ValueError("Symlink in local download path")
    before = path.stat()
    if not stat.S_ISREG(before.st_mode) or not 0 < before.st_size <= MAX_BYTES:
        raise ValueError("Missing, empty, or oversized local download")
    digest = hashlib.sha256()
    read = 0
    with path.open("rb") as stream:
        while True:
            block = stream.read(min(65536, MAX_BYTES + 1 - read))
            if not block:
                break
            read += len(block)
            if read > MAX_BYTES or read > before.st_size:
                raise ValueError("Local download changed while reading")
            digest.update(block)
    after = path.stat()
    if (read != before.st_size or before.st_size != after.st_size
            or before.st_mtime_ns != after.st_mtime_ns
            or before.st_ino != after.st_ino):
        raise ValueError("Local download changed while reading")
    return digest.hexdigest()


def git_blob_hash(repo, commit, relative):
    """Read an exact committed blob, not potentially modified worktree files."""
    cmd = ["git", "-C", str(repo), "show", f"{commit}:{relative}"]
    try:
        done = subprocess.run(cmd, check=True, stdout=subprocess.PIPE,
                              stderr=subprocess.DEVNULL, timeout=12)
    except (subprocess.CalledProcessError, subprocess.TimeoutExpired) as exc:
        raise ValueError("Immutable Git source unavailable") from exc
    if not 0 < len(done.stdout) <= MAX_BYTES:
        raise ValueError("Invalid committed source size")
    return hashlib.sha256(done.stdout).hexdigest()


def compare(downloads, repo, commit):
    if not isinstance(commit, str) or not COMMIT.fullmatch(commit):
        raise ValueError("Exact 40-character Git commit required")
    downloads = Path(downloads).expanduser()
    repo = Path(repo).expanduser()
    if downloads.is_symlink() or not downloads.is_dir() or repo.is_symlink() or not repo.is_dir():
        raise ValueError("Invalid repository or download root")
    rows = []
    for relative in TARGETS:
        source_sha = git_blob_hash(repo, commit, relative)
        download_sha = hash_download(downloads, relative)
        rows.append({"path": relative, "git_sha256": source_sha,
                     "download_sha256": download_sha,
                     "match": source_sha == download_sha})
    matching = all(item["match"] for item in rows)
    return {
        "schema": 1,
        "git_commit": commit,
        "comparison": "MATCH" if matching else "MISMATCH",
        "files": rows,
        "download_origin_authenticated": False,
        "separate_read_only_sftp_identity_verified": False,
        "historical_release_readback_proven": False,
        "independent_ovh_readback": "PENDING",
        "release_administration": "OPEN",
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("download_directory")
    parser.add_argument("repository_directory")
    parser.add_argument("--commit", required=True)
    args = parser.parse_args()
    try:
        report = compare(args.download_directory, args.repository_directory, args.commit)
        print(json.dumps(report, indent=2))
        if report["comparison"] != "MATCH":
            raise SystemExit(1)
    except (OSError, ValueError) as exc:
        parser.exit(2, f"STOP: local readback comparison failed: {exc}\n")


if __name__ == "__main__":
    main()
