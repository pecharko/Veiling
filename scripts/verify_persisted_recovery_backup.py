#!/usr/bin/env python3
"""Verify GitHub-persisted rollback ZIP matches exact audited production baseline.

Run only AFTER successful GitHub Actions artifact download from a separate,
completed read-only backup run, never against a ZIP invented by this job.
No remote connection and no upload permission.
"""
from __future__ import annotations
import argparse
from pathlib import Path
from minimum_safe_recovery_uploader import inspect_rollback_archive
from preflight_catalog_recovery_remote import verify_last_deployed_baseline
from plan_catalog_recovery_release import FILES


def verify_downloaded_code_backup(body: bytes, baseline: dict[str, bytes]) -> None:
    _, archived = inspect_rollback_archive(body)
    expected = set(FILES[2:])
    if set(baseline) != expected:
        raise RuntimeError("Unexpected audited six-file baseline")
    for name in expected:
        if archived[name] != baseline[name]:
            raise RuntimeError("STOP: persisted code backup differs from audited baseline: " + name)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--release-sha", required=True)
    parser.add_argument("--artifact", required=True)
    args = parser.parse_args()
    try:
        path = Path(args.artifact)
        if path.is_symlink() or not path.is_file():
            raise RuntimeError("Artifact absent or symlink")
        if path.stat().st_size > 8 * 1024 * 1024:
            raise RuntimeError("Artifact exceeds safety bound")
        historical = verify_last_deployed_baseline(args.release_sha)
        verify_downloaded_code_backup(path.read_bytes(), historical)
        print("PASS: downloaded persisted code backup matches six audited originals.")
        print("This check does not authorize production writes.")
        return 0
    except Exception:
        print("STOP: persisted rollback artifact check failed (no uploads).")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
