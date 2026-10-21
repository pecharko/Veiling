#!/usr/bin/env python3
"""T-064: read-only, fail-closed original code backup of visitor statistics release.

Only reads three existing PHP files and checks two new files are absent.
Writes a ZIP solely on the GitHub Actions runner, never to OVH. The output is
not a deployment authorization or an executable rollback.
"""
from __future__ import annotations
import argparse
import hashlib
import io
import json
import os
from pathlib import Path
import sys
import zipfile

from plan_visitor_stats_release import plan
from preflight_visitor_stats_remote import (
    NEW, EXISTING, FILES, PreflightStop, inspect, read_file, restore_markers_clear,
)

OBSERVED = {
    "private/bootstrap.php": "e9f0b900b3bc6f58045a5cf4e464d9594854b94c94e1e31f74d728efd0955a9a",
    "catalogus-public/index.php": "d6f5bf173c3fcb419727c33caeaab0bc6e7b54d19e6f78d9b6f716794c78ae27",
    "catalogus-public/kavel.php": "9c375dbcc23b26ac2fa00338f369d085faff3a91f7de1f283808db5197761668",
}
MAX_ZIP = 4 * 1024 * 1024
PREFIX = "originals/"
MANIFEST = "manifest.json"

def digest(b: bytes) -> str:
    return hashlib.sha256(b).hexdigest()

def make_zip(originals: dict[str, bytes], sha: str, run_id: str) -> bytes:
    if set(originals) != EXISTING or set(OBSERVED) != EXISTING:
        raise PreflightStop("Unexpected backup file set")
    if any(digest(data) != OBSERVED[name] for name, data in originals.items()):
        raise PreflightStop("Remote baseline changed; stop")
    report = {
        "format": "visitor-stats-code-rollback-v1",
        "release_sha": sha,
        "source_preflight_run": 39547454464,
        "code_backup_run": run_id,
        "original_sha256": OBSERVED,
        "new_files_expected_absent": sorted(NEW),
        "db_not_included": True,
        "runtime_restore_not_authorized": True,
    }
    output = io.BytesIO()
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        archive.writestr(MANIFEST, json.dumps(report, sort_keys=True))
        for name in sorted(EXISTING):
            archive.writestr(PREFIX + name, originals[name])
    zipped = output.getvalue()
    verify_zip(zipped, originals, sha)
    return zipped

def verify_zip(data: bytes, originals: dict[str, bytes], sha: str) -> None:
    if not 0 < len(data) <= MAX_ZIP:
        raise PreflightStop("Invalid archive size")
    expected = {MANIFEST} | {PREFIX + name for name in EXISTING}
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        entries = archive.infolist()
        if len(entries) != len(expected) or {x.filename for x in entries} != expected:
            raise PreflightStop("Unexpected ZIP member")
        if any(x.is_dir() or x.file_size > 1024 * 1024 for x in entries):
            raise PreflightStop("Invalid archive member")
        metadata = json.loads(archive.read(MANIFEST))
        if metadata["release_sha"] != sha or metadata["original_sha256"] != OBSERVED:
            raise PreflightStop("Archive manifest mismatch")
        for name in EXISTING:
            if archive.read(PREFIX + name) != originals[name]:
                raise PreflightStop("Archive roundtrip mismatch")

def capture(sftp, root: str, release_manifest: dict, run_id: str) -> bytes:
    first = inspect(sftp, root, release_manifest)
    if not first["expected_new_files_absent"] or not first["restore_markers_absent_at_check"]:
        raise PreflightStop("Preflight failed")
    originals = {name: read_file(sftp, root, name) for name in EXISTING}
    if any(digest(data) != OBSERVED[name] for name, data in originals.items()):
        raise PreflightStop("Remote source differs from T-062 baseline")
    second = inspect(sftp, root, release_manifest)
    if not second["expected_new_files_absent"]:
        raise PreflightStop("New files appeared")
    restore_markers_clear(sftp, root)
    if any(read_file(sftp, root, name) != originals[name] for name in EXISTING):
        raise PreflightStop("Remote changed during code backup")
    return make_zip(originals, release_manifest["release_sha"], run_id)

def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--release-sha", required=True)
    parser.add_argument("--run-id", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    try:
        if not args.run_id.isdecimal():
            raise ValueError("Invalid Actions run ID")
        manifest = plan(args.release_sha.lower())
        from deploy_ovh_sftp import connect, remote_root
        client = connect()
        try:
            with client.open_sftp() as sftp:
                archive = capture(sftp, remote_root(), manifest, args.run_id)
        finally:
            client.close()
        destination = Path(args.output)
        if not destination.parent.is_dir() or destination.exists() or destination.is_symlink():
            raise ValueError("Unsafe rollback output destination")
        fd = os.open(str(destination), os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "wb") as handle:
            handle.write(archive)
        print("VERIFIED read-only original-code rollback archive saved on runner.")
        print("Remote writes: 0; database actions: 0; deployment authorization: NONE.")
        return 0
    except Exception:
        print("T-064 STOP: cannot verify original code backup; do not deploy.", file=sys.stderr)
        return 1

if __name__ == "__main__":
    raise SystemExit(main())
