"""Exact, one-shot approval adapter for the existing shared OVH manifest writer.

This module performs local, read-only checks on trusted checked-out main.
It never creates GitHub issues, dispatches workflows, or contacts OVH.
"""
import re
import subprocess
from pathlib import Path

import shared_ovh_release as engine

PREFIX = "DEPLOY SHARED MANIFEST RELEASE"
WORKFLOW = "shared-ovh-release.yml"
FILE_LINE = re.compile(
    r"file=((?:catalogus-public|private)/[A-Za-z0-9_./-]+)\|([a-f0-9]{64})\|([a-f0-9]{64}|ABSENT)"
)
COMMIT = re.compile(r"[a-f0-9]{40}")


def parse_approval(text):
    """Require an ordered, complete list of approved source and remote hashes."""
    if not isinstance(text, str):
        raise ValueError("Invalid approval text")
    lines = text.replace("\r", "").strip().split("\n")
    if not 4 <= len(lines) <= 3 + engine.MAX_FILES or lines[0] != PREFIX:
        raise ValueError("Invalid shared release approval format")
    if not lines[1].startswith("release_id=") or not lines[2].startswith("release_sha="):
        raise ValueError("Invalid shared release approval fields")
    release_id, release_sha = lines[1][11:], lines[2][12:]
    if not engine.ID.fullmatch(release_id) or not COMMIT.fullmatch(release_sha):
        raise ValueError("Invalid shared release identity")
    files = []
    for line in lines[3:]:
        match = FILE_LINE.fullmatch(line)
        if match is None:
            raise ValueError("Invalid approved file or baseline")
        files.append(match.groups())
    if len(set(path for path, _, _ in files)) != len(files):
        raise ValueError("Duplicate approved file")
    return {"release_id": release_id, "release_sha": release_sha, "files": files}


def verify_approval(text, main_sha, *, source_root=None, checkout_sha=None):
    """Match the exact main commit, source bytes, manifest, and remote baselines."""
    data = parse_approval(text)
    if not isinstance(main_sha, str) or not COMMIT.fullmatch(main_sha):
        raise ValueError("Invalid current main SHA")
    root = engine.ROOT if source_root is None else Path(source_root)
    if checkout_sha is None:
        checkout_sha = subprocess.run(
            ("git", "rev-parse", "HEAD"), cwd=root, capture_output=True,
            text=True, check=True,
        ).stdout.strip()
    if data["release_sha"] != main_sha or checkout_sha != main_sha:
        raise ValueError("Checkout or approval no longer equals current main")
    manifest, _payload, _baselines = engine.load_manifest(data["release_id"], source_root=root)
    entries = manifest["files"]
    if [tuple(entry[field] for field in ("path", "source_sha256", "remote_sha256"))
            for entry in entries] != data["files"]:
        raise ValueError("Approved allowlist or hashes differ from the reviewed manifest")
    return data


def dispatch_command(repo, data):
    """Fixed workflow with precisely its two documented workflow_dispatch inputs."""
    if repo != "pecharko/Veiling" or not isinstance(data, dict):
        raise ValueError("Untrusted deployment repository")
    release_id, release_sha = data.get("release_id"), data.get("release_sha")
    if not isinstance(release_id, str) or not engine.ID.fullmatch(release_id):
        raise ValueError("Invalid release ID")
    if not isinstance(release_sha, str) or not COMMIT.fullmatch(release_sha):
        raise ValueError("Invalid release SHA")
    return ("gh", "workflow", "run", WORKFLOW, "--repo", repo, "--ref", "main",
            "-f", "release_id=" + release_id,
            "-f", "confirmation=DEPLOY-" + release_id + "-" + release_sha)
