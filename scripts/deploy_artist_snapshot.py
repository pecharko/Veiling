#!/usr/bin/env python3
"""Dedicated protected artist-compatible snapshot code release.

Loads immutable one-file manifest written after an authenticated pinned SFTP
baseline. The global shared OVH protected-file denylist is never changed.
"""
import json
import os
from pathlib import Path
import re
import sys
from scripts import shared_ovh_release as engine

TARGET = "private/snapshots.php"
RELEASE_ID = "artist-snapshot-20261108"
MANIFEST_NAME = "releases/" + RELEASE_ID + ".json"
PREFIX = "ARTIST-SNAPSHOT-DEPLOY-"


def guarded_load(release_id, *, source_root=None):
    if release_id != RELEASE_ID:
        raise ValueError("Wrong artist snapshot release ID")
    root = engine.ROOT if source_root is None else Path(source_root)
    manifest_path = root / MANIFEST_NAME
    if manifest_path.is_symlink() or not manifest_path.is_file():
        raise ValueError("Authenticated protected snapshot manifest missing")
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    if not isinstance(manifest, dict) or set(manifest) != {"schema", "files"} or manifest["schema"] != 1:
        raise ValueError("Invalid protected snapshot schema")
    entries = manifest["files"]
    if not isinstance(entries, list) or len(entries) != 1:
        raise ValueError("Exactly one protected snapshot target required")
    entry = entries[0]
    if not isinstance(entry, dict) or set(entry) != {"path", "source_sha256", "remote_sha256"} or entry["path"] != TARGET:
        raise ValueError("Wrong protected snapshot path or fields")
    source_sha, remote_sha = entry["source_sha256"], entry["remote_sha256"]
    if not isinstance(source_sha, str) or not engine.HASH.fullmatch(source_sha):
        raise ValueError("Invalid protected snapshot source SHA")
    if not isinstance(remote_sha, str) or not engine.HASH.fullmatch(remote_sha):
        raise ValueError("Protected snapshot must exist with authenticated remote SHA")
    file = root / TARGET
    if file.parent.is_symlink() or file.is_symlink() or not file.is_file() or file.stat().st_size > engine.MAX_FILE:
        raise ValueError("Missing or unsafe snapshot source")
    payload = file.read_bytes()
    if not payload or engine.digest(payload) != source_sha:
        raise ValueError("Protected snapshot source drift")
    return manifest, {TARGET: payload}, {TARGET: remote_sha}


def main():
    if len(sys.argv) != 2 or sys.argv[1] not in ("plan", "deploy"):
        raise ValueError("Usage: deploy_artist_snapshot.py plan|deploy")
    mode = sys.argv[1]
    original_confirm = os.environ.get("DEPLOY_CONFIRMATION")
    if mode == "deploy":
        sha = os.environ.get("GITHUB_SHA", "")
        if not re.fullmatch("[a-f0-9]{40}", sha) or original_confirm != PREFIX + RELEASE_ID + "-" + sha:
            raise ValueError("Separate exact artist snapshot owner authorization missing")
        if os.environ.get("ARTIST_SCHEMA_READY") != "ARTIST-SCHEMA-VERIFIED-20261108":
            raise ValueError("Verified artist schema attestation required before snapshot code release")
    previous_loader, previous_argv = engine.load_manifest, sys.argv
    scripts_path = str(engine.ROOT / "scripts")
    added = scripts_path not in sys.path
    try:
        if added:
            sys.path.insert(0, scripts_path)
        if mode == "deploy":
            os.environ["DEPLOY_CONFIRMATION"] = "DEPLOY-" + RELEASE_ID + "-" + sha
        engine.load_manifest = guarded_load
        sys.argv = ["shared_ovh_release.py", mode, RELEASE_ID]
        engine.run()
    finally:
        engine.load_manifest, sys.argv = previous_loader, previous_argv
        if added:
            sys.path.remove(scripts_path)
        if mode == "deploy":
            if original_confirm is None:
                os.environ.pop("DEPLOY_CONFIRMATION", None)
            else:
                os.environ["DEPLOY_CONFIRMATION"] = original_confirm


if __name__ == "__main__":
    main()
