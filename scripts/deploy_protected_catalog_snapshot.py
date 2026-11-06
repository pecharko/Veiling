#!/usr/bin/env python3
"""Exact catalog snapshot release, using the existing protected one-file adapter.

Hashes observed by the pinned read-only preflight in Actions run 42182888576.
The shared engine retains its generic denylist and all SFTP safety checks.
"""
import hashlib
import os
import re
import sys
from scripts import shared_ovh_release as engine

TARGET = "private/snapshots.php"
RELEASE_ID = "protected-catalog-snapshot-20261106"
SOURCE_SHA = "f8909ee8c06d7fcca81d9a5ce2784d0e87bf60932ea5e0c25f08009e7eb73ebc"
REMOTE_SHA = "1c974785e2cbb7a278320c7cc67d6bcf4c8f1eab42d02b707f18abc08fa0edd2"


def guarded_load(release_id, *, source_root=None):
    if release_id != RELEASE_ID:
        raise ValueError("Invalid protected release ID")
    root = engine.ROOT if source_root is None else source_root
    file = root / TARGET
    if (file.parent.is_symlink() or file.is_symlink() or not file.is_file()
            or not 0 < file.stat().st_size <= engine.MAX_FILE):
        raise ValueError("Missing or unsafe protected source")
    raw = file.read_bytes()
    if not 0 < len(raw) <= engine.MAX_FILE or hashlib.sha256(raw).hexdigest() != SOURCE_SHA:
        raise ValueError("Protected source changed; review and reauthorize")
    return ({"schema": 1, "files": [{"path": TARGET, "source_sha256": SOURCE_SHA,
                                     "remote_sha256": REMOTE_SHA}]},
            {TARGET: raw}, {TARGET: REMOTE_SHA})


def main():
    if len(sys.argv) != 2 or sys.argv[1] not in ("plan", "deploy"):
        raise ValueError("Only plan or deploy")
    mode = sys.argv[1]
    previous_confirmation = os.environ.get("DEPLOY_CONFIRMATION")
    if mode == "deploy":
        main_sha = os.environ.get("GITHUB_SHA", "")
        if (not re.fullmatch("[a-f0-9]{40}", main_sha)
                or previous_confirmation != "PROTECTED-SNAPSHOT-DEPLOY-" + RELEASE_ID + "-" + main_sha):
            raise ValueError("Explicit protected snapshot authorization required")
    # Keep the shared loader/confirmation scoped to this invocation. In particular,
    # a completed plan must never leave the generic snapshot denylist bypassed.
    previous_loader, previous_argv = engine.load_manifest, sys.argv
    scripts_dir = str(engine.ROOT / "scripts")
    added_path = scripts_dir not in sys.path
    try:
        if added_path:
            sys.path.insert(0, scripts_dir)
        if mode == "deploy":
            os.environ["DEPLOY_CONFIRMATION"] = "DEPLOY-" + RELEASE_ID + "-" + main_sha
        engine.load_manifest = guarded_load
        sys.argv = ["shared_ovh_release.py", mode, RELEASE_ID]
        engine.run()
    finally:
        engine.load_manifest, sys.argv = previous_loader, previous_argv
        if added_path:
            sys.path.remove(scripts_dir)
        if mode == "deploy":
            os.environ["DEPLOY_CONFIRMATION"] = previous_confirmation


if __name__ == "__main__":
    main()
