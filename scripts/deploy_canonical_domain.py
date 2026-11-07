#!/usr/bin/env python3
"""Fail-closed canonical-domain release adapter for the existing OVH release engine.

Exact five-file scope, including one protected .htaccess. This adapter is
inert until a separately reviewed manifest with authenticated OVH baselines
exists. Never broadens shared_ovh_release.load_manifest's global allowlist.
"""
import json
import os
import posixpath
from pathlib import Path
import re
import sys

from scripts import shared_ovh_release as engine
from scripts.readonly_canonical_domain_baselines import TARGETS

RELEASE_ID = "canonical-domain-20261107"
MANIFEST_NAME = "releases/canonical-domain-20261107.json"
AUTH_PREFIX = "CANONICAL-DOMAIN-DEPLOY-"
# The only exception to the shared engine's standard allowlist is this
# exact .htaccess filename, guarded by the dedicated manifest and tests.
ALLOWED = frozenset(TARGETS)
HTACCESS = "catalogus-public/.htaccess"


def guarded_load(release_id, *, source_root=None, preserve_remote_htaccess=False, connect=None):
    if release_id != RELEASE_ID:
        raise ValueError("Unapproved canonical release ID")
    root = engine.ROOT if source_root is None else Path(source_root)
    manifest_path = root / MANIFEST_NAME
    if manifest_path.is_symlink() or not manifest_path.is_file():
        raise ValueError("Missing authenticated canonical release manifest")
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    if not isinstance(manifest, dict) or set(manifest) != {"schema", "files"} or manifest["schema"] != 1:
        raise ValueError("Invalid canonical release manifest")
    entries = manifest["files"]
    if not isinstance(entries, list) or len(entries) != len(TARGETS):
        raise ValueError("Canonical release must include every exact target")
    if [entry.get("path") for entry in entries if isinstance(entry, dict)] != list(TARGETS):
        raise ValueError("Canonical release order or scope differs")
    payload, expected = {}, {}
    for entry in entries:
        if not isinstance(entry, dict) or set(entry) != {"path", "source_sha256", "remote_sha256"}:
            raise ValueError("Invalid canonical release entry")
        name = entry["path"]
        if name not in ALLOWED or name in payload:
            raise ValueError("Unexpected canonical release path")
        source, remote = entry["source_sha256"], entry["remote_sha256"]
        if not isinstance(source, str) or not engine.HASH.fullmatch(source):
            raise ValueError("Invalid source SHA")
        if not isinstance(remote, str) or (remote != "ABSENT" and not engine.HASH.fullmatch(remote)):
            raise ValueError("Invalid remote SHA")
        file = root / name
        if file.is_symlink() or file.parent.is_symlink() or not file.is_file():
            raise ValueError("Unsafe canonical release source")
        raw = file.read_bytes()
        if not raw or len(raw) > engine.MAX_FILE or engine.digest(raw) != source:
            raise ValueError("Canonical source drift")
        payload[name], expected[name] = raw, remote

    if preserve_remote_htaccess:
        # Preserve all original Apache rules below the canonical redirect.
        # The shared engine will independently check remote drift again,
        # back up every original byte, and verify upload or rollback.
        original = read_pinned_htaccess(connect or engine.connect, expected[HTACCESS])
        template = payload[HTACCESS].rstrip(b"\r\n")
        merged = template + b"\n\n" + original
        if len(merged) > engine.MAX_FILE:
            raise ValueError("Combined .htaccess exceeds safe release limit")
        payload[HTACCESS] = merged
    return manifest, payload, expected


def read_pinned_htaccess(connect, baseline):
    if baseline == "ABSENT":
        raise ValueError("Expected existing .htaccess must not be absent")
    client, base = connect()
    try:
        with client.open_sftp() as sftp:
            sentinel = engine.read_remote(
                sftp, posixpath.join(base, "catalogus-public/admin/index.php"))
            if sentinel is None or b"dashboard-table" not in sentinel:
                raise ValueError("Wrong pinned OVH root")
            original = engine.read_remote(sftp, posixpath.join(base, HTACCESS))
            if not original or engine.digest(original) != baseline:
                raise ValueError("Protected .htaccess remote drift: STOP")
            return original
    finally:
        client.close()


def canonical_evidence_validate(data, ordinary_validate):
    # The shared evidence schema is unchanged. The single exact dotfile
    # is validated as a proxy path, while all SHA/status rules still apply.
    files = data.get("files", []) if isinstance(data, dict) else []
    if (not isinstance(files, list) or len(files) != len(TARGETS)
            or [row.get("path") for row in files if isinstance(row, dict)] != list(TARGETS)):
        raise ValueError("Canonical evidence is not exact five-file scope")
    normalized = dict(data)
    normalized["files"] = [
        {**row, "path": "catalogus-public/htaccess.php"}
        if row["path"] == HTACCESS else row.copy()
        for row in files
    ]
    ordinary_validate(normalized)
    return data


def main():
    if len(sys.argv) != 2 or sys.argv[1] not in ("plan", "deploy"):
        raise ValueError("Canonical adapter requires plan or deploy")
    mode = sys.argv[1]
    previous_confirmation = os.environ.get("DEPLOY_CONFIRMATION")
    previous_loader, previous_argv = engine.load_manifest, sys.argv
    evidence_module = None
    previous_evidence_validate = None
    scripts_dir = str(engine.ROOT / "scripts")
    added_path = scripts_dir not in sys.path
    try:
        if added_path:
            sys.path.insert(0, scripts_dir)
        if mode == "deploy":
            main_sha = os.environ.get("GITHUB_SHA", "")
            if not re.fullmatch(r"[a-f0-9]{40}", main_sha):
                raise ValueError("Invalid exact main SHA")
            if previous_confirmation != AUTH_PREFIX + RELEASE_ID + "-" + main_sha:
                raise ValueError("Owner-bound canonical production authorization missing")
            os.environ["DEPLOY_CONFIRMATION"] = "DEPLOY-" + RELEASE_ID + "-" + main_sha
        import ovh_deploy_evidence_contract as evidence_module
        previous_evidence_validate = evidence_module.validate
        evidence_module.validate = lambda data: canonical_evidence_validate(data, previous_evidence_validate)
        engine.load_manifest = lambda release_id: guarded_load(release_id, preserve_remote_htaccess=(mode == "deploy"))
        sys.argv = ["shared_ovh_release.py", mode, RELEASE_ID]
        engine.run()
    finally:
        engine.load_manifest, sys.argv = previous_loader, previous_argv
        if evidence_module is not None and previous_evidence_validate is not None:
            evidence_module.validate = previous_evidence_validate
        if added_path:
            sys.path.remove(scripts_dir)
        if mode == "deploy":
            if previous_confirmation is None:
                os.environ.pop("DEPLOY_CONFIRMATION", None)
            else:
                os.environ["DEPLOY_CONFIRMATION"] = previous_confirmation


if __name__ == "__main__":
    main()
