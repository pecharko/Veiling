#!/usr/bin/env python3
"""Production SFTP adapter, deliberately NOT an executable deployment entrypoint.

Only a separately authorized workflow may call this after proving a rollback
artifact is durably stored, performing the independent human release approvals,
and establishing a quiet administration window. This adapter enforces SFTP
preflight AGAIN immediately before any write, source hashes, code backup and
rollback at the I/O boundary. No SFTP connection is created here.
"""
from __future__ import annotations

from minimum_safe_recovery_uploader import upload_with_verified_archive
from preflight_catalog_recovery_remote import (
    EXISTING, NEW_HELPERS, PreflightStop, inspect_sftp_readonly,
)
from plan_catalog_recovery_release import FILES


class ReleaseBlocked(RuntimeError):
    pass


def upload_after_remote_recheck(
    sftp, root: str, manifest: dict, historical_baseline: dict[str, bytes],
    replacement: dict[str, bytes], persisted_zip: bytes,
) -> list[str]:
    """Never call without externally validated release and deploy authorizations.

    A failure at ANY validation means no writes; a failure during transfer
    triggers the existing best-effort reverse-order rollback. This method
    does not interpret attestations as deployment permission.
    """
    if tuple(item.get("path") for item in manifest.get("files", [])) != FILES:
        raise ReleaseBlocked("Unexpected eight-file manifest")
    if set(historical_baseline) != set(EXISTING):
        raise ReleaseBlocked("Invalid remote baseline")
    if set(replacement) != set(FILES):
        raise ReleaseBlocked("Unexpected release source paths")

    # Validate markers, server identity, symlinks and audited contents just
    # before writing. This includes two absent helpers and six old files.
    report = inspect_sftp_readonly(sftp, root, historical_baseline, manifest)
    if report.get("status") != "read_only_baseline_verified":
        raise ReleaseBlocked("Remote safety preflight failed")

    old = {name: None for name in NEW_HELPERS}
    old.update(historical_baseline)

    # upload_with_verified_archive checks exact source SHA/PHP lint markers,
    # archive contents, live-file drift and readback after each write.
    # It rolls back touched files when anything fails.
    return upload_with_verified_archive(
        sftp, root, old, replacement, manifest, persisted_zip
    )
