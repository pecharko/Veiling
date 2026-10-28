#!/usr/bin/env python3
"""B4: verify GitHub owner/browser evidence against independently fetched snapshots.

Pure and offline. The caller must obtain issue/comment through trusted GitHub REST
and OVH attestation from a *separate* trusted independent reader. Never writes.
"""
import re
from controller_b4_acceptance_contract import decide

REPO = "pecharko/Veiling"
SHA = re.compile(r"^[a-f0-9]{40}$")


def evaluate(*, issue, comment, expected_owner, evidence, ovh_attestation,
             run_id, release_sha, release_id):
    """Build a B4 decision from bound identities; never grant write authority."""
    invalid = {"status": "BLOCKED", "reason": "untrusted_owner_or_ovh_evidence",
               "annotation_permitted": False, "writes_performed": False}
    if (not isinstance(issue, dict) or not isinstance(comment, dict)
            or not isinstance(expected_owner, str) or not expected_owner
            or type(run_id) is not int or run_id < 1
            or not isinstance(release_sha, str) or not SHA.fullmatch(release_sha)
            or not isinstance(release_id, str) or not release_id):
        return invalid
    issue_id = issue.get("number")
    comment_id = comment.get("id")
    if (type(issue_id) is not int or issue_id < 1
            or type(comment_id) is not int or comment_id < 1
            or issue.get("repository_url") != "https://api.github.com/repos/" + REPO
            or comment.get("issue_url") !=
               "https://api.github.com/repos/" + REPO + "/issues/" + str(issue_id)
            or issue.get("pull_request") is not None
            or issue.get("user", {}).get("login") != expected_owner
            or comment.get("user", {}).get("login") != expected_owner
            or comment.get("updated_at") != comment.get("created_at")
            or not isinstance(comment.get("created_at"), str)
            or not isinstance(comment.get("body"), str)):
        return invalid
    # Explicit machine-parsable owner statement, not "green" or inferred CI.
    expected_body = ("B4-BROWSER-ACCEPT " + release_id + " " +
                     release_sha + " " + str(run_id))
    if comment["body"].strip() != expected_body:
        return invalid
    # This offline component cannot authenticate a caller-supplied OVH
    # attestation. Require a separate explicit trusted-origin provenance claim,
    # and preserve the need for caller-side independent credential verification.
    if (not isinstance(ovh_attestation, dict)
            or ovh_attestation.get("source") != "independent_ovh_read"
            or ovh_attestation.get("verified_by_trusted_reader") is not True
            or ovh_attestation.get("run_id") != run_id
            or ovh_attestation.get("release_sha") != release_sha
            or ovh_attestation.get("release_id") != release_id
            or ovh_attestation.get("status") != "VERIFIED"):
        return invalid
    if not isinstance(evidence, dict) or not isinstance(evidence.get("review"), dict):
        return invalid
    separately_verified = {**evidence, "review": {
        **evidence["review"], "independent_remote_readback": "VERIFIED"}}
    return decide(evidence=separately_verified, run_id=run_id,
                  sha=release_sha, release_id=release_id,
                  owner_acceptance={"verified_owner": True,
                                    "confirmed_browser": True,
                                    "run_id": run_id,
                                    "release_sha": release_sha,
                                    "release_id": release_id,
                                    "comment_id": comment_id,
                                    "issue": issue_id})
