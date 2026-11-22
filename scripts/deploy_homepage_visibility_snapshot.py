#!/usr/bin/env python3
"""Bind the shared OVH writer to the reviewed one-file snapshot release."""
import json
import os
from pathlib import Path
import re
import sys

from scripts import shared_ovh_release as engine

TARGET = "private/snapshots.php"
RELEASE_ID = "homepage-sectie-zichtbaarheid-snapshot-20261122"
MANIFEST = "releases/" + RELEASE_ID + ".json"
SOURCE = "5ade15588c6f463a8601662f1bb92aa2ca89cb43cf0a8d67b110d9eb09943be4"
BASELINE = "23e2fec0e50a3c378da304171b62cf81316fe714cb38468cfafd44888b73204e"
WORKFLOW = "pecharko/Veiling/.github/workflows/homepage-visibility-snapshot-protected-release.yml@refs/heads/main"
BACKUP_READY = "HOMEPAGE-SNAPSHOT-FULL-DB-PHOTOS-BACKUP-20261122"


def unique_object(pairs):
    value = {}
    for key, item in pairs:
        if key in value:
            raise ValueError("Duplicate manifest key")
        value[key] = item
    return value


def load_manifest(release_id, *, source_root=None):
    """Accept only the immutable source and authenticated baseline under review."""
    if release_id != RELEASE_ID or "private/snapshots" not in engine.PROTECTED:
        raise ValueError("Wrong release ID or missing central snapshot protection")
    root = engine.ROOT if source_root is None else Path(source_root)
    manifest = root / MANIFEST
    source = root / TARGET
    for path, limit in ((manifest, 16384), (source, engine.MAX_FILE)):
        if (root.is_symlink() or path.parent.is_symlink() or path.is_symlink()
                or not path.is_file() or not 0 < path.stat().st_size <= limit):
            raise ValueError("Missing or unsafe release file")
    data = json.loads(manifest.read_text(encoding="utf-8"), object_pairs_hook=unique_object)
    expected = {"schema": 1, "files": [
        {"path": TARGET, "source_sha256": SOURCE, "remote_sha256": BASELINE},
    ]}
    if data != expected or type(data["schema"]) is not int:
        raise ValueError("Protected manifest does not match the reviewed one-file release")
    payload = source.read_bytes()
    if engine.digest(payload) != SOURCE:
        raise ValueError("Protected snapshot source drift")
    return data, {TARGET: payload}, {TARGET: BASELINE}


def authorize_dispatch(env):
    sha = env.get("GITHUB_SHA", "")
    run_id = env.get("GITHUB_RUN_ID", "")
    required = {
        "GITHUB_ACTIONS": "true",
        "GITHUB_REPOSITORY": "pecharko/Veiling",
        "GITHUB_ACTOR": "pecharko",
        "GITHUB_TRIGGERING_ACTOR": "pecharko",
        "GITHUB_EVENT_NAME": "workflow_dispatch",
        "GITHUB_WORKFLOW_REF": WORKFLOW,
        "GITHUB_REF": "refs/heads/main",
        "GITHUB_RUN_ATTEMPT": "1",
        "DEPLOY_CONFIRMATION": f"HOMEPAGE-SNAPSHOT-DEPLOY-{RELEASE_ID}-{sha}",
        "HOMEPAGE_SNAPSHOT_BACKUP_READY": BACKUP_READY,
    }
    if (not re.fullmatch(r"[0-9a-f]{40}", sha) or not re.fullmatch(r"[1-9][0-9]*", run_id)
            or any(env.get(key) != expected for key, expected in required.items())):
        raise ValueError("Exact owner dispatch and verified full database and photo backup required")
    return f"DEPLOY-{RELEASE_ID}-{sha}"


def main():
    if len(sys.argv) != 2 or sys.argv[1] not in ("plan", "deploy"):
        raise ValueError("Usage: deploy_homepage_visibility_snapshot.py plan|deploy")
    mode = sys.argv[1]
    original_confirmation = os.environ.get("DEPLOY_CONFIRMATION")
    confirmation = authorize_dispatch(os.environ) if mode == "deploy" else None
    original_loader, original_argv = engine.load_manifest, sys.argv
    # The shared script loads its evidence validator by module name.
    scripts_dir = str(engine.ROOT / "scripts")
    sys.path.insert(0, scripts_dir)
    try:
        if confirmation is not None:
            os.environ["DEPLOY_CONFIRMATION"] = confirmation
        engine.load_manifest = load_manifest
        sys.argv = ["shared_ovh_release.py", mode, RELEASE_ID]
        engine.run()
    finally:
        engine.load_manifest, sys.argv = original_loader, original_argv
        sys.path.pop(0)
        if confirmation is not None:
            os.environ["DEPLOY_CONFIRMATION"] = original_confirmation


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("HOMEPAGE SNAPSHOT RELEASE STOPPED:", exc, file=sys.stderr)
        sys.exit(1)
