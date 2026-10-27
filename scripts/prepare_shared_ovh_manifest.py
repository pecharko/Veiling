#!/usr/bin/env python3
"""Prepare a reviewed release manifest from real read-only SFTP evidence.

Called only by the GitHub Actions preparation workflow. No production writes.
"""
import json
import os
from pathlib import Path
import re
import subprocess
import sys

from scripts.shared_ovh_readonly_baselines import validate_paths

ROOT = Path(__file__).resolve().parents[1]
ID = re.compile(r"[a-z0-9][a-z0-9-]{2,60}\Z")


def request_paths(release_id):
    if not ID.fullmatch(release_id):
        raise ValueError("Invalid release ID")
    src = ROOT / "release-requests" / (release_id + ".json")
    if not src.is_file() or src.is_symlink():
        raise ValueError("Missing release request")
    obj = json.loads(src.read_text(encoding="utf-8"))
    if not isinstance(obj, dict) or set(obj) != {"schema", "paths"} or obj["schema"] != 1:
        raise ValueError("Invalid release request")
    paths = obj["paths"]
    if not isinstance(paths, list) or not 1 <= len(paths) <= 25 or any(not isinstance(x, str) for x in paths):
        raise ValueError("Invalid path list")
    if validate_paths(",".join(paths)) != paths:
        raise ValueError("Invalid or duplicate paths")
    return paths


def prepare(release_id):
    paths = request_paths(release_id)
    env = os.environ.copy()
    env["RELEASE_FILES"] = ",".join(paths)
    subprocess.run([sys.executable, "-m", "scripts.shared_ovh_readonly_baselines"],
                   cwd=str(ROOT), env=env, check=True)
    evidence_path = ROOT / "shared-ovh-readonly-baselines.json"
    evidence = json.loads(evidence_path.read_text(encoding="utf-8"))
    if set(evidence) != {"schema", "files"} or evidence["schema"] != 1:
        raise ValueError("Invalid baseline evidence")
    if [entry.get("path") for entry in evidence["files"]] != paths:
        raise ValueError("Baseline paths mismatch")
    target = ROOT / "releases" / (release_id + ".json")
    if target.exists():
        raise ValueError("Manifest already exists; do not overwrite approved baseline")
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(json.dumps(evidence, indent=2) + "\n", encoding="utf-8")
    from scripts.shared_ovh_release import load_manifest
    load_manifest(release_id)
    print("READ-ONLY VERIFIED MANIFEST", target.relative_to(ROOT))
    return target


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python -m scripts.prepare_shared_ovh_manifest <release-id>")
    prepare(sys.argv[1])
