#!/usr/bin/env python3
"""Offline-only transaction engine for the eight-file recovery release.

This module never connects to SFTP and has no executable production entrypoint.
The caller supplies a mockable SFTP-like object, the exact remote root, expected
old bytes (None for the two new helpers), and reviewed new bytes.
Do not wire this to production until separate backup/approval gates are audited.
"""
from __future__ import annotations
import hashlib
import posixpath
import stat

from plan_catalog_recovery_release import FILES

NEW_HELPERS = frozenset(FILES[:2])
MAX_BYTES = 1024 * 1024


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def remote_path(root: str, relative: str) -> str:
    if not root.startswith("/") or ".." in root.split("/") or not relative in FILES:
        raise ValueError("Unsafe release location")
    return posixpath.join(root, relative)


def read(sftp, path: str, *, absent_allowed: bool = False) -> bytes | None:
    try:
        info = sftp.lstat(path)
    except FileNotFoundError:
        if absent_allowed:
            return None
        raise
    if not stat.S_ISREG(info.st_mode) or not 1 <= info.st_size <= MAX_BYTES:
        raise RuntimeError("Remote file is not regular or size is invalid")
    with sftp.open(path, "rb") as handle:
        body = handle.read(MAX_BYTES + 1)
    if len(body) != info.st_size:
        raise RuntimeError("Remote file modified while reading")
    return body


def verify_release_inputs(original: dict, replacement: dict) -> None:
    if set(original) != set(FILES) or set(replacement) != set(FILES):
        raise ValueError("Release allowlist mismatch")
    for name in FILES:
        old, new = original[name], replacement[name]
        if (old is None) != (name in NEW_HELPERS):
            raise ValueError("Expected precisely two absent helpers")
        if old is not None and (not isinstance(old, bytes) or not 1 <= len(old) <= MAX_BYTES):
            raise ValueError("Invalid audited original")
        if not isinstance(new, bytes) or not 1 <= len(new) <= MAX_BYTES:
            raise ValueError("Invalid replacement")


def simulate_transaction(sftp, root: str, original: dict, replacement: dict) -> list[str]:
    """Test-only; mutates supplied fake SFTP. No production entrypoint.

    On failure, attempt rollback in reverse order. Never suppress a rollback
    problem: report all failures, and require an operator to reconcile them.
    """
    verify_release_inputs(original, replacement)
    for name in FILES:
        now = read(sftp, remote_path(root, name), absent_allowed=name in NEW_HELPERS)
        if now != original[name]:
            raise RuntimeError("Production drift before release: " + name)

    touched = []
    try:
        for name in FILES:
            path = remote_path(root, name)
            touched.append(name)  # record before attempting a possibly partial write
            with sftp.open(path, "wb") as handle:
                handle.write(replacement[name])
                handle.flush()
            if read(sftp, path) != replacement[name]:
                raise RuntimeError("Post-write SHA mismatch: " + name)
        return touched
    except Exception as upload_error:
        rollback_errors = []
        for name in reversed(touched):
            path = remote_path(root, name)
            old = original[name]
            try:
                if old is None:
                    if read(sftp, path, absent_allowed=True) is not None:
                        sftp.remove(path)
                    if read(sftp, path, absent_allowed=True) is not None:
                        raise RuntimeError("New helper remained after rollback")
                else:
                    with sftp.open(path, "wb") as handle:
                        handle.write(old)
                        handle.flush()
                    if read(sftp, path) != old:
                        raise RuntimeError("Rollback SHA mismatch")
            except Exception as exc:
                rollback_errors.append(name + ": " + type(exc).__name__)
        if rollback_errors:
            raise RuntimeError("UPLOAD FAILED; ROLLBACK INCOMPLETE: " +
                               ", ".join(rollback_errors)) from upload_error
        raise RuntimeError("UPLOAD FAILED; offline rollback verified") from upload_error
