#!/usr/bin/env python3
"""Dedicated fail-closed artist overview snapshot release adapter.

Offline preparation only: no dispatch workflow, production code, secrets,
existing workflows, historical releases or central protected gates are changed.
This controller is dormant until a separately approved dedicated workflow is
installed, the owner authorizes the exact production manifest, and a fresh
pinned-hostkey SFTP drift check passes.
"""
import json
import os
from pathlib import Path
import re
import sys

from scripts import shared_ovh_release as engine

TARGET = "private/snapshots.php"
RELEASE_ID = "issues638639-snapshot-20261120"
MANIFEST_NAME = "releases/" + RELEASE_ID + ".json"
PREFIX = "ISSUES638639-SNAPSHOT-DEPLOY-"
BACKUP_ATTESTATION = "ISSUES638639-FULL-DB-PHOTOS-BACKUP-20261120"
BACKUP_KEY = "ISSUES638639_FULL_BACKUP_READY"
WORKFLOW_REF = "pecharko/Veiling/.github/workflows/issues638639-snapshot-protected-release.yml@refs/heads/main"


def _unique_object(pairs):
    obj = {}
    for key, value in pairs:
        if key in obj:
            raise ValueError("Duplicate protected manifest key")
        obj[key] = value
    return obj


def guarded_load(release_id, *, source_root=None):
    """Accept exactly one independently baselined protected snapshot source."""
    if release_id != RELEASE_ID:
        raise ValueError("Wrong artist-overview snapshot release ID")
    root = engine.ROOT if source_root is None else Path(source_root)
    manifest_path = root / MANIFEST_NAME
    if (root.is_symlink() or manifest_path.parent.is_symlink() or manifest_path.is_symlink()
            or not manifest_path.is_file() or not 0 < manifest_path.stat().st_size <= 16384):
        raise ValueError("Authenticated overview snapshot manifest missing")
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"), object_pairs_hook=_unique_object)
    if not isinstance(manifest, dict) or set(manifest) != {"schema", "files"} or type(manifest["schema"]) is not int or manifest["schema"] != 1:
        raise ValueError("Invalid overview snapshot manifest schema")
    entries = manifest["files"]
    if not isinstance(entries, list) or len(entries) != 1:
        raise ValueError("Exactly one protected snapshot target required")
    entry = entries[0]
    if (not isinstance(entry, dict)
            or set(entry) != {"path", "source_sha256", "remote_sha256"}
            or entry["path"] != TARGET):
        raise ValueError("Wrong protected overview snapshot path or fields")
    source_sha = entry["source_sha256"]
    remote_sha = entry["remote_sha256"]
    if not isinstance(source_sha, str) or not engine.HASH.fullmatch(source_sha):
        raise ValueError("Invalid overview snapshot source SHA")
    if not isinstance(remote_sha, str) or not engine.HASH.fullmatch(remote_sha):
        raise ValueError("Authenticated OVH remote SHA required; ABSENT is prohibited")
    source = root / TARGET
    if (source.parent.is_symlink() or source.is_symlink() or not source.is_file()
            or not 0 < source.stat().st_size <= engine.MAX_FILE):
        raise ValueError("Missing or unsafe overview snapshot source")
    payload = source.read_bytes()
    if not payload or engine.digest(payload) != source_sha:
        raise ValueError("Protected overview snapshot source drift")
    if (b"41lokeren-v8-catalog-only" not in payload
            or b"'show_report_issue_link','show_artists_overview','updated_at'" not in payload
            or b"SNAP_INSERT" not in payload
            or b"'artist_name','artist_url','artist_instagram_url','artist_intro'" not in payload):
        raise ValueError("Snapshot source lacks approved artist overview schema")
    if TARGET not in engine.PROTECTED and "private/snapshots" not in engine.PROTECTED:
        raise ValueError("Protected snapshot path lost central guard")
    return manifest, {TARGET: payload}, {TARGET: remote_sha}


def main():
    if len(sys.argv) != 2 or sys.argv[1] not in ("plan", "deploy"):
        raise ValueError("Usage: deploy_artist_overview_snapshot.py plan|deploy")
    mode = sys.argv[1]
    old_confirmation = os.environ.get("DEPLOY_CONFIRMATION")
    if mode == "deploy":
        commit = os.environ.get("GITHUB_SHA", "")
        expected = PREFIX + RELEASE_ID + "-" + commit
        if (os.environ.get("GITHUB_ACTIONS") != "true"
                or os.environ.get("GITHUB_REPOSITORY") != "pecharko/Veiling"
                or os.environ.get("GITHUB_WORKFLOW_REF") != WORKFLOW_REF
                or os.environ.get("GITHUB_EVENT_NAME") != "workflow_dispatch"
                or not os.environ.get("GITHUB_RUN_ID", "").isdigit()
                or int(os.environ.get("GITHUB_RUN_ID", "0")) <= 0
                or os.environ.get("GITHUB_REF") != "refs/heads/main"
                or not re.fullmatch(r"[a-f0-9]{40}", commit)
                or old_confirmation != expected):
            raise ValueError("Exact owner approval, main ref, and protected dispatch required")
        if os.environ.get(BACKUP_KEY) != BACKUP_ATTESTATION:
            raise ValueError("Independent complete database and photo backup attestation required")
    old_loader, old_argv = engine.load_manifest, sys.argv
    script_dir = str(engine.ROOT / "scripts")
    add_path = script_dir not in sys.path
    try:
        if add_path:
            sys.path.insert(0, script_dir)
        if mode == "deploy":
            os.environ["DEPLOY_CONFIRMATION"] = "DEPLOY-" + RELEASE_ID + "-" + commit
        engine.load_manifest = guarded_load
        sys.argv = ["shared_ovh_release.py", mode, RELEASE_ID]
        engine.run()
    finally:
        engine.load_manifest, sys.argv = old_loader, old_argv
        if add_path:
            sys.path.remove(script_dir)
        if mode == "deploy":
            if old_confirmation is None:
                os.environ.pop("DEPLOY_CONFIRMATION", None)
            else:
                os.environ["DEPLOY_CONFIRMATION"] = old_confirmation


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("ISSUES638639 SNAPSHOT RELEASE STOPPED:", exc, file=sys.stderr)
        sys.exit(1)
