#!/usr/bin/env python3
"""Manual read-only SFTP capture of audited pre-release PHP originals.

Writes only to the GitHub Actions runner's private output directory. Never
writes or modifies remote SFTP paths. Production connection needs separate
manual authorization and GitHub environment access.
"""
from __future__ import annotations
import argparse
import os
from pathlib import Path
import stat
import sys
from plan_catalog_recovery_release import source_manifest
from preflight_catalog_recovery_remote import (
    EXISTING, NEW_HELPERS, PreflightStop, inspect_sftp_readonly,
    verify_last_deployed_baseline,
)
from recovery_rollback_zip import make_rollback_zip, verify_rollback_zip


def capture_readonly(sftp, root: str, baseline: dict, manifest: dict) -> bytes:
    report = inspect_sftp_readonly(sftp, root, baseline, manifest)
    if report["status"] != "read_only_baseline_verified":
        raise PreflightStop("Remote safety preflight not verified")
    from preflight_catalog_recovery_remote import remote_regular_file
    original = {name: None for name in NEW_HELPERS}
    for name in EXISTING:
        body = remote_regular_file(sftp, root, name)
        if body != baseline[name]:
            raise PreflightStop("Remote code changed during backup: " + name)
        original[name] = body
    # Re-check drift and restoration markers even after collecting bytes.
    inspect_sftp_readonly(sftp, root, baseline, manifest)
    data = make_rollback_zip(original)
    verify_rollback_zip(data, original)
    return data


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--release-sha", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    try:
        manifest = source_manifest(args.release_sha.lower())
        baseline = verify_last_deployed_baseline(args.release_sha.lower())
        from deploy_ovh_sftp import connect, remote_root
        root = remote_root()
        client = connect()
        try:
            with client.open_sftp() as sftp:
                archive = capture_readonly(sftp, root, baseline, manifest)
        finally:
            client.close()
        path = Path(args.output)
        if not path.parent.is_dir() or path.exists() or path.is_symlink():
            raise RuntimeError("Unsafe local rollback artifact destination")
        fd = os.open(str(path), os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "wb") as stream:
            stream.write(archive)
        print("READ-ONLY code rollback archive assembled, verified, saved locally.")
        print("No remote writes or database access. No release authorization.")
        return 0
    except Exception:
        print("STOP: read-only rollback capture failed; no deploy permitted.",
              file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
