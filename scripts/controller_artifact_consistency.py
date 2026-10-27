#!/usr/bin/env python3
"""Offline consistency checks for independently retrieved release artifacts.

No GitHub/OVH access. A consistent untrusted document is NOT deployment proof.
"""
import re

from ovh_deploy_evidence_contract import validate

SHA = re.compile(r"^[a-f0-9]{40}$")


def inspect_release_evidence(document, artifacts, *, run_id, release_sha,
                             expected_paths):
    """Return structured read-only evidence; fail closed on missing metadata."""
    source = {"run_id": run_id, "release_sha": release_sha}
    unknown = lambda why: {"status": "INCOMPLETE", "reason": why, "source": source}
    if (type(run_id) is not int or run_id <= 0 or
            not isinstance(release_sha, str) or not SHA.fullmatch(release_sha) or
            not isinstance(expected_paths, list) or not expected_paths or
            len(set(expected_paths)) != len(expected_paths)):
        raise ValueError("Invalid trusted release identity or exact allowlist")
    if not isinstance(document, dict):
        return unknown("missing_evidence_document")
    try:
        validated = validate(document)
    except (ValueError, TypeError, KeyError):
        return unknown("invalid_evidence_contract")
    if validated["run_id"] != run_id or validated["release_sha"] != release_sha:
        return {"status": "FAILED", "reason": "release_identity_mismatch",
                "source": source}
    if [item["path"] for item in validated["files"]] != expected_paths:
        return {"status": "FAILED", "reason": "allowlist_mismatch",
                "source": source}
    if validated["status"] != "VERIFIED":
        return unknown("release_not_verified")
    if not isinstance(artifacts, list):
        return unknown("artifact_inventory_missing")
    names = ["shared-ovh-evidence-" + str(run_id),
             "shared-ovh-rollback-" + str(run_id)]
    for name in names:
        matches = [a for a in artifacts if isinstance(a, dict)
                   and a.get("name") == name]
        if len(matches) != 1:
            return unknown("missing_or_duplicate_artifact")
        a = matches[0]
        if (a.get("expired") is not False or type(a.get("id")) is not int or
                a["id"] <= 0 or not isinstance(a.get("workflow_run"), dict) or
                a["workflow_run"].get("id") != run_id):
            return unknown("expired_or_unbound_artifact")
    if validated["rollback_artifact"] != next(
            a["id"] for a in artifacts if a.get("name") == names[1]):
        return {"status": "FAILED", "reason": "rollback_id_mismatch",
                "source": source}
    # Contract, file hashes and inventory match. GitHub-run conclusion,
    # artifact bytes, actual remote readback and browser review remain separate.
    return {"status": "INCOMPLETE", "reason": "consistent_metadata_not_remote_proof",
            "source": source}
