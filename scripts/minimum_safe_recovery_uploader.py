#!/usr/bin/env python3
"""Minimum-safe eight-file uploader core; NOT connected to live OVH.

The caller must furnish a trusted, already persisted rollback artifact, a
source manifest for the selected release commit, a verified remote baseline,
and a separately authorized SFTP session. No CLI/production entrypoint exists.

This deliberately cannot prove GitHub artifact persistence, offsite data
backup or maintenance-window authorization. Those are external hard gates.
"""
from __future__ import annotations

import io
import json
import zipfile
from recovery_backup_evidence import verify_code_backup
from recovery_release_transaction import (
    NEW_HELPERS, simulate_backup_guarded_transaction, verify_release_inputs,
)
from recovery_rollback_zip import CODE_PREFIX, MANIFEST, MAX_TOTAL
from plan_catalog_recovery_release import FILES, sha256


def inspect_rollback_archive(archive: bytes) -> tuple[dict, dict[str, bytes]]:
    """Reject malformed or extraneous entries without extracting the ZIP."""
    if not isinstance(archive, bytes) or not 0 < len(archive) <= MAX_TOTAL:
        raise ValueError("Invalid persisted rollback archive size")
    expected = {MANIFEST} | {CODE_PREFIX + p for p in FILES if p not in NEW_HELPERS}
    with zipfile.ZipFile(io.BytesIO(archive), "r") as z:
        members = z.infolist()
        if (len(members) != len(expected)
                or {m.filename for m in members} != expected
                or any(m.is_dir() or m.file_size > 1024 * 1024 for m in members)):
            raise ValueError("Unsafe rollback artifact contents")
        manifest = json.loads(z.read(MANIFEST))
        saved = {p: z.read(CODE_PREFIX + p)
                 for p in FILES if p not in NEW_HELPERS}
    verify_code_backup(manifest, saved)
    return manifest, saved


def validate_source_manifest(source_manifest: dict, replacement: dict[str, bytes]) -> None:
    """Pin exactly eight source hashes and the requested complete SHA."""
    entries = source_manifest.get("files")
    commit = source_manifest.get("release_sha")
    if (not isinstance(commit, str) or len(commit) != 40
            or any(x not in "0123456789abcdef" for x in commit)
            or not isinstance(entries, list) or len(entries) != len(FILES)
            or [x.get("path") for x in entries] != list(FILES)
            or set(replacement) != set(FILES)):
        raise ValueError("Invalid release source manifest")
    for entry in entries:
        path = entry["path"]
        body = replacement[path]
        if (not isinstance(body, bytes) or
                entry.get("sha256") != sha256(body)
                or entry.get("php_lint") != "pass"):
            raise ValueError("Source manifest drift: " + path)


def upload_with_verified_archive(
    sftp, root: str, original: dict[str, bytes | None],
    replacement: dict[str, bytes], source_manifest: dict,
    persisted_archive: bytes,
) -> list[str]:
    """Offline-testable upload with mandatory archive check and rollback.

    NEVER call until an external gate proves the backup artifact was actually
    stored, the current remote baseline is correct, and consent is granted.
    """
    validate_source_manifest(source_manifest, replacement)
    verify_release_inputs(original, replacement)
    manifest, saved = inspect_rollback_archive(persisted_archive)
    return simulate_backup_guarded_transaction(
        sftp, root, original, replacement, manifest, saved
    )
