#!/usr/bin/env python3
"""Explicitly invoked eight-file production release. No autonomous execution."""
import argparse
from pathlib import Path
from run_authorized_eightfile_release import run_authorized_release

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--release-sha", required=True)
    parser.add_argument("--artifact", required=True)
    args = parser.parse_args()
    path = Path(args.artifact)
    if path.is_symlink() or not path.is_file() or not 0 < path.stat().st_size <= 8 * 1024 * 1024:
        raise RuntimeError("Missing or unsafe externally persisted code backup")
    run_authorized_release(
        release_sha=args.release_sha,
        downloaded_rollback_zip=path.read_bytes(),
        independently_authorized=True,
    )
    print("Eight-file release completed; manual HTTPS acceptance remains required.")

if __name__ == "__main__":
    main()
