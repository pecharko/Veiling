#!/usr/bin/env python3
"""Dedicated fail-closed two-file snapshot-v8 OVH release adapter.

This is NOT a standalone uploader. It uses the existing verified SFTP release
engine only after owner-bound GitHub Actions dispatch, an externally verified
full data/photo backup attestation, and an immutable manifest containing two
authenticated remote SHA-256 baselines. Historical release paths stay protected.
"""
import json
import os
from pathlib import Path
import re
import sys

from scripts import shared_ovh_release as engine
from scripts.snapshot_v8_review import review_source

RELEASE_ID = "snapshot-v8-catalog-only-20261115"
MANIFEST_NAME = "releases/" + RELEASE_ID + ".json"
FILES = ("private/snapshots.php", "catalogus-public/admin/backup.php")
PREFIX = "SNAPSHOT-V8-DEPLOY-"
BACKUP_ATTESTATION = "SNAPSHOT-V8-FULL-BACKUP-VERIFIED-20261115"
BACKUP_KEY = "SNAPSHOT_V8_BACKUP_READY"
REPOSITORY = "pecharko/Veiling"
WORKFLOW_REF = REPOSITORY + "/.github/workflows/snapshot-v8-protected-release.yml@refs/heads/main"
COMMIT = re.compile(r"[a-f0-9]{40}\Z")


def _unique_object(pairs):
    """Do not silently accept duplicate security-critical JSON keys."""
    document = {}
    for key, value in pairs:
        if key in document:
            raise ValueError("Duplicate release manifest key")
        document[key] = value
    return document


def guarded_load(release_id, *, source_root=None):
    """Exact two-file protected override; never extends the shared allowlist."""
    if release_id != RELEASE_ID:
        raise ValueError("Wrong dedicated snapshot-v8 release ID")
    root = engine.ROOT if source_root is None else Path(source_root)
    manifest_file = root / MANIFEST_NAME
    if (root.is_symlink() or manifest_file.parent.is_symlink()
            or manifest_file.is_symlink() or not manifest_file.is_file()
            or not 0 < manifest_file.stat().st_size <= 16384):
        raise ValueError("Authenticated snapshot-v8 manifest missing or unsafe")
    manifest = json.loads(
        manifest_file.read_text(encoding="utf-8"), object_pairs_hook=_unique_object
    )
    if (not isinstance(manifest, dict)
            or set(manifest) != {"schema", "files"}
            or type(manifest["schema"]) is not int or manifest["schema"] != 1
            or not isinstance(manifest["files"], list)
            or len(manifest["files"]) != len(FILES)):
        raise ValueError("Invalid snapshot-v8 manifest schema or file count")

    if "private/snapshots" not in engine.PROTECTED:
        raise ValueError("Shared protected snapshot guard changed")

    payload, remote = {}, {}
    for expected, entry in zip(FILES, manifest["files"]):
        if (not isinstance(entry, dict)
                or set(entry) != {"path", "source_sha256", "remote_sha256"}
                or entry["path"] != expected):
            raise ValueError("Unexpected or out-of-order snapshot-v8 release path")
        source_hash, remote_hash = entry["source_sha256"], entry["remote_sha256"]
        if (not isinstance(source_hash, str)
                or not engine.HASH.fullmatch(source_hash)
                or not isinstance(remote_hash, str)
                or not engine.HASH.fullmatch(remote_hash)):
            raise ValueError("Both authenticated source and existing OVH SHA-256 hashes required")
        source_file = root / expected
        parents = (source_file.parent, source_file.parent.parent)
        if (any(parent.is_symlink() for parent in parents)
                or source_file.is_symlink() or not source_file.is_file()
                or not 0 < source_file.stat().st_size <= engine.MAX_FILE):
            raise ValueError("Missing, oversized or unsafe protected source: " + expected)
        data = source_file.read_bytes()
        if engine.digest(data) != source_hash:
            raise ValueError("Immutable snapshot-v8 source drift: " + expected)
        if expected == FILES[0]:
            try:
                review_source(data.decode("utf-8"))
            except (UnicodeError, ValueError) as exc:
                raise ValueError("Unapproved catalog-only v8 snapshot contract") from exc
        else:
            # Preflight validates a protected application entrypoint, not only a hash.
            for marker in (b"snap_verified($name);", b"$safety=snap_create($pdo);",
                           b"snap_restore($pdo,$name);", b"catalog_data_lock($pdo);"):
                if marker not in data:
                    raise ValueError("Unsafe snapshot-v8 admin restore entrypoint")
        payload[expected] = data
        remote[expected] = remote_hash
    return manifest, payload, remote


def check_owner_authorization():
    sha = os.environ.get("GITHUB_SHA", "")
    expected = PREFIX + RELEASE_ID + "-" + sha
    if (os.environ.get("GITHUB_ACTIONS") != "true"
            or os.environ.get("GITHUB_EVENT_NAME") != "workflow_dispatch"
            or os.environ.get("GITHUB_REPOSITORY") != REPOSITORY
            or os.environ.get("GITHUB_REF") != "refs/heads/main"
            or os.environ.get("GITHUB_WORKFLOW_REF") != WORKFLOW_REF
            or not COMMIT.fullmatch(sha)
            or not os.environ.get("GITHUB_RUN_ID", "").isdigit()
            or int(os.environ.get("GITHUB_RUN_ID", "0")) <= 0
            or os.environ.get("DEPLOY_CONFIRMATION") != expected):
        raise ValueError("Exact owner approval, immutable main and protected dispatch required")
    if os.environ.get(BACKUP_KEY) != BACKUP_ATTESTATION:
        raise ValueError("Independent full database/photo backup attestation required")
    return sha


def main():
    if len(sys.argv) != 2 or sys.argv[1] not in ("plan", "deploy"):
        raise ValueError("Usage: deploy_snapshot_v8.py plan|deploy")
    mode = sys.argv[1]
    original_confirmation = os.environ.get("DEPLOY_CONFIRMATION")
    if mode == "deploy":
        commit = check_owner_authorization()
    # Fail before invoking the engine, even in plan mode, when any manifest
    # entry, protected source, or snapshot-v8 restore contract has drifted.
    guarded_load(RELEASE_ID)
    old_loader, old_argv = engine.load_manifest, sys.argv
    script_dir = str(engine.ROOT / "scripts")
    added_path = script_dir not in sys.path
    try:
        if added_path:
            sys.path.insert(0, script_dir)
        engine.load_manifest = guarded_load
        if mode == "deploy":
            os.environ["DEPLOY_CONFIRMATION"] = "DEPLOY-" + RELEASE_ID + "-" + commit
        sys.argv = ["shared_ovh_release.py", mode, RELEASE_ID]
        engine.run()
    finally:
        engine.load_manifest, sys.argv = old_loader, old_argv
        if added_path:
            sys.path.remove(script_dir)
        if mode == "deploy":
            if original_confirmation is None:
                os.environ.pop("DEPLOY_CONFIRMATION", None)
            else:
                os.environ["DEPLOY_CONFIRMATION"] = original_confirmation


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("SNAPSHOT V8 PROTECTED RELEASE STOPPED:", exc, file=sys.stderr)
        sys.exit(1)
