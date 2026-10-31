"""Strict offline verification of GitHub API evidence for the Phase C sandbox.

This module does not request tokens, call GitHub, or write to GitHub.
The caller must obtain all inputs from authenticated GitHub API endpoints.
"""
import re

SHA = re.compile(r"^[0-9a-f]{40}$")
BRANCH = re.compile(r"^test/phase-c-[a-z0-9-]{1,40}$")
TARGET_PATH = "tests/phase_c_sandbox_test.php"
OWNER_ID = 343879284
REPO = "pecharko/Veiling"


def check_github_owner_evidence(pr, issue, comment, auth, contents):
    """Return an advisory denial or verified proposal; NEVER authorizes write."""
    deny = {"verified": False, "write_allowed": False}
    if not all(isinstance(x, dict) for x in (pr, issue, comment, auth, contents)):
        return dict(deny, reason="Malformed evidence")
    if pr.get("base", {}).get("repo", {}).get("full_name") != REPO:
        return dict(deny, reason="Wrong base repository")
    if pr.get("head", {}).get("repo", {}).get("full_name") != REPO:
        return dict(deny, reason="Fork not allowed")
    branch = pr.get("head", {}).get("ref")
    if not isinstance(branch, str) or not BRANCH.fullmatch(branch):
        return dict(deny, reason="Not isolated sandbox")
    if pr.get("state") != "open" or pr.get("merged") is not False:
        return dict(deny, reason="PR not open")
    if issue.get("state") != "open" or issue.get("pull_request") is not None:
        return dict(deny, reason="Authorization issue invalid")
    if issue.get("repository_url") != "https://api.github.com/repos/" + REPO:
        return dict(deny, reason="Wrong authorization repository")
    if issue.get("user", {}).get("id") != OWNER_ID or comment.get("user", {}).get("id") != OWNER_ID:
        return dict(deny, reason="Owner GitHub ID mismatch")
    if comment.get("issue_url") != issue.get("url"):
        return dict(deny, reason="Comment not attached to issue")
    head = pr.get("head", {}).get("sha")
    blob = contents.get("sha")
    if not isinstance(head, str) or not SHA.fullmatch(head):
        return dict(deny, reason="Invalid head SHA")
    if not isinstance(blob, str) or not SHA.fullmatch(blob):
        return dict(deny, reason="Invalid blob SHA")
    if contents.get("path") != TARGET_PATH or contents.get("type") != "file":
        return dict(deny, reason="Wrong target file")
    expected = "PHASE-C-SANDBOX-ONCE pr=" + str(pr.get("number")) + " head=" + head + " blob=" + blob
    if comment.get("body") != expected:
        return dict(deny, reason="No exact owner approval")
    if auth.get("issue_id") != issue.get("id") or auth.get("comment_id") != comment.get("id"):
        return dict(deny, reason="Authorization IDs not pinned")
    if auth.get("used") is not False or auth.get("attempt") != 0 or type(auth.get("attempt")) is not int:
        return dict(deny, reason="Replay or invalid attempt")
    return {"verified": True, "write_allowed": False, "head_sha": head,
            "blob_sha": blob, "path": TARGET_PATH, "branch": branch,
            "reason": "Offline verification only; no replay-resistant write transaction yet"}
