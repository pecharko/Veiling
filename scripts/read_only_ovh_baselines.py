#!/usr/bin/env python3
"""Read only OVH SHA-256 baselines for explicitly selected public catalogue files."""
import argparse
import json
import posixpath
from pathlib import Path

from shared_ovh_release import PROTECTED, ROOT, connect, digest, read_remote

SUFFIXES = (".php", ".css", ".js", ".webp", ".png", ".jpg", ".jpeg", ".svg", ".html")
MAX_PATHS = 25


def validate_paths(raw):
    paths = raw.split()
    if not 1 <= len(paths) <= MAX_PATHS or len(paths) != len(set(paths)):
        raise ValueError("Expected 1-25 unique paths")
    for path in paths:
        parts = path.split("/")
        if (not path.startswith("catalogus-public/") or not path.endswith(SUFFIXES)
                or any(part in ("", ".", "..") or part.startswith(".") for part in parts)
                or "\\" in path or any(path.startswith(p) for p in PROTECTED)):
            raise ValueError("Invalid or protected path")
    return paths


def observe(paths, reader):
    return [{"path": path, "remote_sha256": "ABSENT" if (data := reader(path)) is None else digest(data)}
            for path in paths]


def run(paths):
    client, base = connect()
    try:
        with client.open_sftp() as sftp:
            sentinel = read_remote(sftp, posixpath.join(base, "catalogus-public/admin/index.php"))
            if sentinel is None or b"dashboard-table" not in sentinel:
                raise ValueError("Wrong OVH remote root")
            return observe(paths, lambda path: read_remote(sftp, posixpath.join(base, path)))
    finally:
        client.close()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--paths-file", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    paths = validate_paths(Path(args.paths_file).read_text(encoding="utf-8"))
    rows = run(paths)
    evidence = {"schema": 1, "kind": "read_only_ovh_remote_baselines",
                "commit": __import__("os").environ.get("GITHUB_SHA", ""),
                "run_id": __import__("os").environ.get("GITHUB_RUN_ID", ""),
                "writes_performed": False, "independent_ovh_acceptance": False, "files": rows}
    Path(args.output).write_text(json.dumps(evidence, indent=2) + "\n", encoding="utf-8")
    for row in rows:
        print("OBSERVED_REMOTE", row["path"], row["remote_sha256"])


if __name__ == "__main__":
    main()
