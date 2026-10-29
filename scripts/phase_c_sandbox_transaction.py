"""Offline Phase C sandbox repair transaction planner. Never makes GitHub API calls.

The inputs are evidence collected by a future *trusted* controller. This planner
cannot authenticate the owner by itself; its output is never write authorization.
"""
import hashlib
import re

SHA = re.compile(r"^[0-9a-f]{40}$")
BRANCH = re.compile(r"^test/phase-c-[a-z0-9-]{1,40}$")


def plan_sandbox_repair(evidence):
    deny = {"decision": "DENY", "write_allowed": False}
    fields = {"repo", "owner_login", "issue_author", "comment_author", "issue_open",
              "pr_branch", "pr_head", "approved_head", "file_path", "blob_sha",
              "approved_blob", "old_content", "new_content", "attempt_count"}
    if not isinstance(evidence, dict) or set(evidence) != fields:
        return dict(deny, reason="Invalid evidence contract")
    if evidence["repo"] != "pecharko/Veiling":
        return dict(deny, reason="Wrong repository")
    owner = evidence["owner_login"]
    if (not isinstance(owner, str) or not owner or evidence["issue_author"] != owner
            or evidence["comment_author"] != owner or evidence["issue_open"] is not True):
        return dict(deny, reason="Owner evidence mismatch")
    branch = evidence["pr_branch"]
    if not isinstance(branch, str) or not BRANCH.fullmatch(branch):
        return dict(deny, reason="Not sandbox branch")
    for a, b in (("pr_head", "approved_head"), ("blob_sha", "approved_blob")):
        if not isinstance(evidence[a], str) or not SHA.fullmatch(evidence[a]) or evidence[a] != evidence[b]:
            return dict(deny, reason="SHA drift")
    if evidence["file_path"] != "tests/phase_c_sandbox_test.php":
        return dict(deny, reason="Outside immutable test fixture")
    if type(evidence["attempt_count"]) is not int or evidence["attempt_count"] != 0:
        return dict(deny, reason="Attempt already spent")
    old, new = evidence["old_content"], evidence["new_content"]
    if not isinstance(old, str) or not isinstance(new, str):
        return dict(deny, reason="Invalid contents")
    if len(old.encode()) > 2048 or len(new.encode()) > 2048 or not old.startswith("<?php"):
        return dict(deny, reason="Oversize/non-PHP")
    if old.count("return flase;") != 1 or new != old.replace("return flase;", "return false;"):
        return dict(deny, reason="Unexpected edit")
    return {
        "decision": "PROPOSAL_ONLY", "write_allowed": False,
        "file_path": evidence["file_path"], "expected_head": evidence["pr_head"],
        "expected_blob": evidence["blob_sha"],
        "proposed_sha256": hashlib.sha256(new.encode("utf-8")).hexdigest(),
        "reason": "Trusted API owner checks and one-time replay protection not implemented",
    }
