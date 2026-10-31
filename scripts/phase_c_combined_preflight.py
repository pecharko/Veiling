"""Strict evidence evaluator for C2 single-call atomic sandbox proposal.

No network, writer transport, token handling or execution authority.
Evidence must be assembled from separately authenticated GitHub reads.
An affirmative report is only a REVIEW artifact, never an authorization.
"""
import hashlib
import re

from scripts.phase_c_atomic_combined_review import propose_atomic_combined
from scripts.phase_c_c2_inert_graphql_envelope import HEAD, NONCE, BRANCH, REPOSITORY_NODE

OLD_BLOB = "a16bb38acbde1422389e1fcb4835c20688f594b3"
NEW_BLOB = "9bb4e9774e6244c143975c00bcac4826d4895317"
NEW_DIGEST = "013a57991e6285797587766f1bfb422d5f27045a1ca91531dd772b44958e9323"
TARGET = "tests/phase_c_sandbox_test.php"
SHA = re.compile(r"^[0-9a-f]{40}$")


def verify_preflight(evidence, reservation, advance):
    deny = {"decision": "DENY", "write_allowed": False,
            "network_allowed": False, "retry_allowed": False,
            "activation_allowed": False, "merge_allowed": False}
    if type(evidence) is not dict:
        return dict(deny, reason="Non-object evidence")
    required = {
        "repository", "repository_node_id", "pr_number", "pr_open",
        "pr_draft", "pr_from_fork", "pr_head", "sandbox_ref",
        "observed_sandbox_head", "original_blob", "old_content",
        "new_content", "candidate_oid", "candidate_parent",
        "candidate_paths", "candidate_blob", "nonce_ref",
        "nonce_absent_read", "owner_issue", "owner_comment_verified",
        "app_installation_id", "ruleset_tag_active", "ruleset_branch_active",
        "ruleset_bypass_count", "writer_permissions_verified",
    }
    if set(evidence) != required:
        return dict(deny, reason="Evidence schema drift")
    old = evidence["old_content"]
    new = evidence["new_content"]
    if type(old) is not str or type(new) is not str or len(old.encode()) > 2048:
        return dict(deny, reason="Invalid content evidence")
    if old.count("return flase;") != 1 or new != old.replace("return flase;", "return false;", 1):
        return dict(deny, reason="Not the exact isolated correction")
    blob = "blob " + str(len(new.encode())) + "\0"
    computed_blob = hashlib.sha1(blob.encode() + new.encode()).hexdigest()
    valid = (
        evidence["repository"] == "pecharko/Veiling"
        and evidence["repository_node_id"] == REPOSITORY_NODE
        and type(evidence["pr_number"]) is int and evidence["pr_number"] == 426
        and evidence["pr_open"] is True and evidence["pr_draft"] is True
        and evidence["pr_from_fork"] is False
        and evidence["pr_head"] == HEAD
        and evidence["sandbox_ref"] == BRANCH
        and evidence["observed_sandbox_head"] == HEAD
        and evidence["original_blob"] == OLD_BLOB
        and evidence["candidate_parent"] == HEAD
        and type(evidence["candidate_oid"]) is str
        and SHA.fullmatch(evidence["candidate_oid"]) is not None
        and evidence["candidate_oid"] != HEAD
        and evidence["candidate_paths"] == [TARGET]
        and evidence["candidate_blob"] == NEW_BLOB
        and computed_blob == NEW_BLOB
        and hashlib.sha256(new.encode()).hexdigest() == NEW_DIGEST
        and evidence["nonce_ref"] == NONCE
        and evidence["nonce_absent_read"] is True
        and type(evidence["owner_issue"]) is int and evidence["owner_issue"] == 427
        and evidence["owner_comment_verified"] is True
        and type(evidence["app_installation_id"]) is int
        and evidence["app_installation_id"] == 176547610
        and evidence["ruleset_tag_active"] is True
        and evidence["ruleset_branch_active"] is True
        and type(evidence["ruleset_bypass_count"]) is int
        and evidence["ruleset_bypass_count"] == 0
        and evidence["writer_permissions_verified"] is True
    )
    if not valid:
        return dict(deny, reason="Scope, identity, nonce or candidate mismatch")
    proposed = propose_atomic_combined(reservation, advance)
    if proposed.get("decision") != "REVIEW_ONLY":
        return dict(deny, reason="Combined transaction shape rejected")
    updates = proposed["envelope"]["variables"]["input"]["refUpdates"]
    if updates[1]["afterOid"] != evidence["candidate_oid"]:
        return dict(deny, reason="Unverified candidate identifier")
    return dict(deny, decision="REVIEW_ONLY", candidate_oid=evidence["candidate_oid"],
                reason="Untrusted evidence values cannot establish authenticated App or owner identity")
