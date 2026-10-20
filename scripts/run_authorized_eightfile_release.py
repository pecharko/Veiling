#!/usr/bin/env python3
"""Eight-file release SFTP command, requires independently verified caller gates.

This script intentionally has NO deploy CLI entrypoint: integration remains
disabled pending security review and *separate* final production consent.
All network I/O occurs only if a reviewed caller invokes run_authorized_release.
"""
from __future__ import annotations

from pathlib import Path
from plan_catalog_recovery_release import FILES, MAX_FILE_BYTES, source_manifest
from preflight_catalog_recovery_remote import verify_last_deployed_baseline
from verified_recovery_sftp_adapter import upload_after_remote_recheck
from verify_persisted_recovery_backup import verify_downloaded_code_backup


def run_authorized_release(
    *,
    release_sha: str,
    downloaded_rollback_zip: bytes,
    independently_authorized: bool,
) -> list[str]:
    """Not a permission provider: caller MUST verify both human approvals."""
    if independently_authorized is not True:
        raise PermissionError("STOP: release authorization must be independently established")
    # All local requirements are validated BEFORE network connection.
    manifest = source_manifest(release_sha)
    baseline = verify_last_deployed_baseline(release_sha)
    verify_downloaded_code_backup(downloaded_rollback_zip, baseline)
    root_dir = Path(__file__).resolve().parents[1]
    replacements: dict[str, bytes] = {}
    for name in FILES:
        p = root_dir / name
        if p.is_symlink() or not p.is_file():
            raise RuntimeError("Unexpected source path: " + name)
        body = p.read_bytes()
        if not 1 <= len(body) <= MAX_FILE_BYTES:
            raise RuntimeError("Unsafe source length: " + name)
        replacements[name] = body

    # Connection uses explicitly configured root and pinned host key.
    from deploy_ovh_sftp import connect, remote_root
    root = remote_root()
    client = connect()
    try:
        with client.open_sftp() as sftp:
            return upload_after_remote_recheck(
                sftp, root, manifest, baseline, replacements, downloaded_rollback_zip
            )
    finally:
        client.close()
