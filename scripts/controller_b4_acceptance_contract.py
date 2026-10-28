#!/usr/bin/env python3
"""B4 pure acceptance/closeout decision. No GitHub, OVH, or other writes.

A decision is only a proposal for a later separately authorized issue/PR note.
Browser acceptance must be supplied as an independently verified owner action,
never inferred from artifact, run success, CI or a free-form 'green' string.
"""
import re

SHA = re.compile(r"^[0-9a-f]{40}$")
RELEASE = re.compile(r"^[a-z0-9][a-z0-9-]{2,60}$")


def decide(*, evidence, run_id, sha, release_id, owner_acceptance=None):
    """Return fail-closed, machine-readable review; never perform annotation."""
    identity = {"run_id": run_id, "release_sha": sha, "release_id": release_id}
    result = {"schema": 1, "identity": identity, "status": "PENDING",
              "reason": "incomplete_evidence", "annotation_permitted": False,
              "writes_performed": False, "browser_acceptance": "PENDING"}
    if (type(run_id) is not int or run_id <= 0 or not isinstance(sha, str)
            or not SHA.fullmatch(sha) or not isinstance(release_id, str)
            or not RELEASE.fullmatch(release_id)):
        return {**result, "status": "BLOCKED", "reason": "invalid_immutable_identity"}
    if not isinstance(evidence, dict) or evidence.get("read_only") is not True:
        return {**result, "status": "BLOCKED", "reason": "untrusted_evidence"}
    provenance = evidence.get("provenance")
    review = evidence.get("review")
    if not isinstance(provenance, dict) or not isinstance(review, dict):
        return {**result, "status": "BLOCKED", "reason": "missing_provenance"}
    github = provenance.get("github_actions")
    if (not isinstance(github, dict) or github.get("run_id") != run_id
            or github.get("release_sha") != sha or github.get("status") != "VERIFIED"):
        return {**result, "status": "BLOCKED", "reason": "run_not_verified"}
    # Existing B1/B2 evidence cannot alone establish independent server state.
    if review.get("independent_remote_readback") != "VERIFIED":
        return {**result, "reason": "independent_ovh_readback_pending"}
    if not isinstance(owner_acceptance, dict):
        return {**result, "reason": "owner_browser_acceptance_pending"}
    # Trusted caller must separately authenticate these immutable properties
    # against GitHub REST. No free-text message is sufficient.
    if (owner_acceptance.get("verified_owner") is not True
            or owner_acceptance.get("confirmed_browser") is not True
            or owner_acceptance.get("run_id") != run_id
            or owner_acceptance.get("release_sha") != sha
            or owner_acceptance.get("release_id") != release_id
            or type(owner_acceptance.get("comment_id")) is not int
            or owner_acceptance["comment_id"] <= 0
            or not isinstance(owner_acceptance.get("issue"), int)
            or type(owner_acceptance["issue"]) is not int
            or owner_acceptance["issue"] <= 0):
        return {**result, "status": "BLOCKED", "reason": "unbound_or_unauthenticated_acceptance"}
    # This module has no write capability. A later controlled registrar must
    # independently confirm both proofs and idempotency before annotating.
    return {**result, "status": "READY_FOR_REVIEW",
            "reason": "verified_evidence_and_owner_browser_acceptance",
            "browser_acceptance": "OWNER_CONFIRMED",
            "annotation_permitted": False,
            "owner_comment_id": owner_acceptance["comment_id"],
            "owner_issue": owner_acceptance["issue"]}
