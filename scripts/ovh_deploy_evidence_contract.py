#!/usr/bin/env python3
"""Offline validation contract for future OVH deployment evidence.

This is NOT connected to a production workflow or deployment trigger.
"""
import json
import re
from pathlib import Path

SHA256 = re.compile(r"^[0-9a-f]{64}$")
COMMIT = re.compile(r"^[0-9a-f]{40}$")
RELEASE_ID = re.compile(r"^[a-z0-9][a-z0-9-]{2,60}$")
STATES = {"VERIFIED", "FAILED", "ROLLED_BACK", "ROLLBACK_FAILED", "NOT_STARTED"}


def validate(data):
    """Validate an untrusted evidence document; return normalized verified claims."""
    if not isinstance(data, dict) or set(data) != {
        "schema", "release_id", "release_sha", "run_id", "status",
        "files", "rollback_artifact", "browser_acceptance"
    }:
        raise ValueError("Invalid evidence keys")
    if type(data["schema"]) is not int or data["schema"] != 1:
        raise ValueError("Unknown evidence schema")
    if not isinstance(data["release_id"], str) or not RELEASE_ID.fullmatch(data["release_id"]):
        raise ValueError("Invalid release id")
    if not isinstance(data["release_sha"], str) or not COMMIT.fullmatch(data["release_sha"]):
        raise ValueError("Invalid commit")
    if type(data["run_id"]) is not int or data["run_id"] <= 0:
        raise ValueError("Invalid run id")
    if data["status"] not in STATES:
        raise ValueError("Invalid status")
    if data["browser_acceptance"] not in ("PENDING", "ACCEPTED", "REJECTED"):
        raise ValueError("Invalid acceptance")
    if data["browser_acceptance"] == "ACCEPTED" and data["status"] != "VERIFIED":
        raise ValueError("Acceptance without verified deployment")
    artifact = data["rollback_artifact"]
    if artifact is not None and (type(artifact) is not int or artifact <= 0):
        raise ValueError("Invalid artifact id")
    entries = data["files"]
    if not isinstance(entries, list) or not 1 <= len(entries) <= 25:
        raise ValueError("Invalid file list")
    seen = set()
    for item in entries:
        if not isinstance(item, dict) or set(item) != {
            "path", "source_sha256", "baseline_sha256", "readback_sha256"
        }:
            raise ValueError("Invalid file evidence")
        path = item["path"]
        if not isinstance(path, str) or not path.startswith(("catalogus-public/", "private/")):
            raise ValueError("Invalid path")
        if path in seen or any(part in ("", ".", "..") or part.startswith(".") for part in path.split("/")):
            raise ValueError("Duplicate/unsafe path")
        seen.add(path)
        for field in ("source_sha256", "baseline_sha256", "readback_sha256"):
            digest = item[field]
            if field == "baseline_sha256" and digest == "ABSENT":
                continue
            if field == "readback_sha256" and digest is None and data["status"] != "VERIFIED":
                continue
            if not isinstance(digest, str) or not SHA256.fullmatch(digest):
                raise ValueError("Invalid digest: " + field)
        if data["status"] == "VERIFIED" and item["readback_sha256"] != item["source_sha256"]:
            raise ValueError("Verified status without matching readback")
    return data


def load(path):
    return validate(json.loads(Path(path).read_text(encoding="utf-8")))
