"""Advisory two-stage, issue-scoped one-shot GitHub GraphQL ref plan.

The nonce creation is an atomic conditional single-ref operation that MUST
be committed before a separate, branch-head compare-and-swap. Unlike a
single two-ref atomic update, this leaves the nonce spent if a later branch
update fails. No calls are made here; planning is never authorization.
The future trusted worker must authenticate GitHub and never retry after
an ambiguous or failed first attempt.
"""
import hashlib
import re

ZERO_SHA = "0" * 40
REPO = "pecharko/Veiling"
TARGET = "tests/phase_c_sandbox_test.php"
SHA = re.compile(r"^[a-f0-9]{40}$")
DIGEST = re.compile(r"^[a-f0-9]{64}$")
BRANCH = re.compile(r"^test/phase-c-[a-z0-9-]{1,40}$")
REPO_NODE_ID = "R_kgDOVNLxvg"  # Read from canonical repository metadata

# Query is inert data. No code in this file can submit it.
MUTATION = (
    "mutation PhaseCAtomicRefs($input: UpdateRefsInput!) { "
    "updateRefs(input: $input) { clientMutationId } }"
)


def propose_atomic_update_refs(evidence):
    """Build a strictly bounded, non-authorizing two-ref mutation preview."""
    denied = {"decision": "DENY", "write_allowed": False, "merge_allowed": False,
              "dispatch_allowed": False, "production_authorized": False}
    if not isinstance(evidence, dict):
        return dict(denied, reason="Invalid evidence")
    ids = ("pr_number", "issue_id", "comment_id")
    if any(type(evidence.get(k)) is not int or evidence[k] <= 0 for k in ids):
        return dict(denied, reason="Invalid immutable owner evidence IDs")
    if (evidence.get("repo") != REPO or
            evidence.get("owner_verified") is not True or
            evidence.get("pr_open") is not True or
            evidence.get("from_fork") is not False):
        return dict(denied, reason="Owner, repo or PR not verified")
    node_id = evidence.get("repository_node_id")
    if node_id != REPO_NODE_ID:
        return dict(denied, reason="Invalid repository node ID")
    branch = evidence.get("branch")
    if not isinstance(branch, str) or not BRANCH.fullmatch(branch):
        return dict(denied, reason="Unsafe target branch")
    if evidence.get("target_path") != TARGET:
        return dict(denied, reason="Wrong target path")

    for name in ("head_sha", "observed_head_sha", "blob_sha",
                 "observed_blob_sha", "proposed_commit_sha", "proposed_parent_sha"):
        value = evidence.get(name)
        if not isinstance(value, str) or not SHA.fullmatch(value):
            return dict(denied, reason="Invalid SHA: " + name)
    head = evidence["head_sha"]
    candidate = evidence["proposed_commit_sha"]
    if (evidence["observed_head_sha"] != head or
            evidence["observed_blob_sha"] != evidence["blob_sha"] or
            evidence["proposed_parent_sha"] != head or candidate == head):
        return dict(denied, reason="Head, blob or parent drift")
    if (evidence.get("proposed_changed_paths") != [TARGET] or
            evidence.get("proposed_commit_verified") is not True):
        return dict(denied, reason="Candidate must have verified one-file diff")

    old = evidence.get("old_content")
    new = evidence.get("new_content")
    if not isinstance(old, str) or not isinstance(new, str):
        return dict(denied, reason="Missing file contents")
    if (not old.startswith("<?php") or len(old.encode("utf-8")) > 2048 or
            len(new.encode("utf-8")) > 2048 or
            old.count("return flase;") != 1 or
            new != old.replace("return flase;", "return false;", 1)):
        return dict(denied, reason="Non-deterministic or oversized correction")
    expected = evidence.get("approved_new_sha256")
    if (not isinstance(expected, str) or not DIGEST.fullmatch(expected) or
            hashlib.sha256(new.encode("utf-8")).hexdigest() != expected):
        return dict(denied, reason="Result digest mismatch")

    issue_id = evidence["issue_id"]
    comment_id = evidence["comment_id"]
    # The immutable issue ID, not the comment ID, defines the unique nonce:
    # approving a second comment must NOT reset the single-use issue budget.
    nonce_ref = "refs/tags/phase-c-once-issue-" + str(issue_id)
    sandbox_ref = "refs/heads/" + branch
    # The issue ID, not the comment ID, is the once-only budget.
    # Step 1: atomically create a tag pointing at the original, known commit.
    # Step 2: ONLY IF step 1 was confirmed by a future trusted writer, CAS
    # the sandbox head to the candidate. Never delete/reuse the tag, even when
    # the branch update fails or times out. This module never executes steps.
    reservation_update = {
        "name": nonce_ref, "beforeOid": ZERO_SHA, "afterOid": head,
        "force": False,
    }
    branch_update = {
        "name": sandbox_ref, "beforeOid": head, "afterOid": candidate,
        "force": False,
    }
    reserve = {
        "query": MUTATION,
        "variables": {"input": {
            "repositoryId": node_id,
            "clientMutationId": "phase-c-reserve-" + str(issue_id) + "-" +
                                str(comment_id) + "-" + head[:12],
            "refUpdates": [reservation_update],
        }},
    }
    advance = {
        "query": MUTATION,
        "variables": {"input": {
            "repositoryId": node_id,
            "clientMutationId": "phase-c-advance-" + str(issue_id) + "-" +
                                str(comment_id) + "-" + head[:12],
            "refUpdates": [branch_update],
        }},
    }
    return {
        "decision": "REVIEW_ONLY", "write_allowed": False,
        "merge_allowed": False, "dispatch_allowed": False,
        "production_authorized": False,
        "reason": "Two conditional plans only; no trusted writer or permission",
        "nonce_ref": nonce_ref, "sandbox_ref": sandbox_ref,
        "reservation": reserve,
        "branch_update": advance,
        "reservation_before_branch": True,
        "never_retry_same_issue": True,
        "never_delete_nonce": True,
        "expected_parent": head,
        "expected_target_sha256": expected,
        "expected_changed_paths": [TARGET],
    }
