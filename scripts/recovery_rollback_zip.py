#!/usr/bin/env python3
"""Offline, bounded rollback ZIP creation and round-trip verification.

Not a GitHub artifact uploader and not a production SFTP client.
"""
from __future__ import annotations
import io
import json
import zipfile
from recovery_backup_evidence import code_backup_manifest, verify_code_backup
from plan_catalog_recovery_release import FILES

MAX_TOTAL = 8 * 1024 * 1024
CODE_PREFIX = "originals/"
MANIFEST = "manifest.json"


def make_rollback_zip(original: dict[str, bytes | None]) -> bytes:
    manifest = code_backup_manifest(original)
    out = io.BytesIO()
    with zipfile.ZipFile(out, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        archive.writestr(MANIFEST, json.dumps(manifest, sort_keys=True))
        for name in FILES[2:]:
            archive.writestr(CODE_PREFIX + name, original[name])
    body = out.getvalue()
    if len(body) > MAX_TOTAL:
        raise ValueError("Code rollback ZIP exceeds maximum size")
    verify_rollback_zip(body, original)
    return body


def verify_rollback_zip(body: bytes, original: dict[str, bytes | None]) -> None:
    if len(body) > MAX_TOTAL or not body:
        raise ValueError("Invalid ZIP size")
    names = {MANIFEST} | {CODE_PREFIX + name for name in FILES[2:]}
    with zipfile.ZipFile(io.BytesIO(body)) as archive:
        members = archive.infolist()
        if len(members) != len(names) or {x.filename for x in members} != names:
            raise ValueError("Unsafe archive entries")
        if any(x.is_dir() or x.file_size > 1024 * 1024 for x in members):
            raise ValueError("Invalid archive member")
        manifest = json.loads(archive.read(MANIFEST))
        saved = {name: archive.read(CODE_PREFIX + name) for name in FILES[2:]}
    verify_code_backup(manifest, saved)
    if {name: original[name] for name in FILES[2:]} != saved:
        raise ValueError("Archived bytes mismatch current audited originals")
