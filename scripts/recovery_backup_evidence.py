#!/usr/bin/env python3
"""Offline-only release backup evidence validation; never connects to production.

A declaration is not proof of a backup: the operator must independently verify
both backup artifacts before any real deployment is considered.
"""
from __future__ import annotations
import hashlib
import json
import re
from pathlib import Path
from plan_catalog_recovery_release import FILES

NEW = frozenset(FILES[:2])
HASH = re.compile(r"^[0-9a-f]{64}$")


def digest(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def code_backup_manifest(original: dict[str, bytes | None]) -> dict:
    """Describe six saved original byte streams and two absent new helpers."""
    if set(original) != set(FILES):
        raise ValueError("Incorrect code backup allowlist")
    entries = []
    for path in FILES:
        value = original[path]
        if path in NEW:
            if value is not None:
                raise ValueError("New helper unexpectedly exists")
            entries.append({"path": path, "previous": "absent"})
        else:
            if not isinstance(value, bytes) or not value:
                raise ValueError("Missing or empty original code: " + path)
            entries.append({"path": path, "previous": "saved", "sha256": digest(value), "bytes": len(value)})
    return {"kind": "41lokeren-code-rollback-manifest-v1",
            "entries": entries, "upload_allowed": False}


def verify_code_backup(manifest: dict, saved: dict[str, bytes]) -> None:
    """Verify immutable original bytes against a separately archived manifest."""
    if manifest.get("kind") != "41lokeren-code-rollback-manifest-v1":
        raise ValueError("Unknown rollback format")
    entries = manifest.get("entries")
    if not isinstance(entries, list) or [x.get("path") for x in entries] != list(FILES):
        raise ValueError("Unsafe rollback manifest paths or order")
    if set(saved) != set(FILES) - NEW:
        raise ValueError("Missing or unexpected rollback code")
    for item in entries:
        name = item["path"]
        if name in NEW:
            if item != {"path": name, "previous": "absent"}:
                raise ValueError("Incorrect new-file rollback marker")
        else:
            content = saved[name]
            if (item.get("previous") != "saved" or not isinstance(content, bytes)
                    or item.get("bytes") != len(content)
                    or item.get("sha256") != digest(content)):
                raise ValueError("Corrupt code rollback artifact: " + name)


def verify_external_data_evidence(evidence: dict) -> None:
    """Validate shape only: NEVER represent this as independent offsite proof."""
    if (evidence.get("kind") != "41lokeren-external-data-only-evidence-v1"
            or evidence.get("offsite_verified_by_operator") is not True
            or evidence.get("independent_copy_confirmed") is not True
            or not isinstance(evidence.get("size_bytes"), int)
            or evidence["size_bytes"] <= 0
            or not isinstance(evidence.get("sha256"), str)
            or HASH.fullmatch(evidence["sha256"]) is None):
        raise ValueError("Insufficient external data backup attestation")
    # Shape validation cannot independently prove a file exists offsite.
