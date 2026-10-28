#!/usr/bin/env python3
"""B4 inert, idempotent GitHub acceptance annotation plan. NO API writes.

Caller MUST authenticate owner/OVH evidence and target issue independently.
Never use this pure planner as proof of external evidence or issue identity.
"""
import re

SHA = re.compile(r"^[0-9a-f]{40}$")
MARKER_PREFIX = "<!-- veiling-b4-accepted:"
MAX_COMMENTS = 100


def plan(decision, *, issue_number, existing_comments):
    """Return a bounded plan; always require separately authorized writer."""
    result = {"status": "BLOCKED", "reason": "invalid_preconditions",
              "write_permitted": False, "write_executed": False,
              "target_issue": issue_number, "proposed_body": None}
    if (not isinstance(decision, dict)
            or decision.get("status") != "READY_FOR_REVIEW"
            or decision.get("annotation_permitted") is not False
            or decision.get("writes_performed") is not False):
        return result
    identity = decision.get("identity")
    if not isinstance(identity, dict):
        return result
    run_id, sha, release_id = (identity.get("run_id"), identity.get("release_sha"),
                               identity.get("release_id"))
    if (type(issue_number) is not int or issue_number < 1
            or issue_number != decision.get("owner_issue")
            or type(run_id) is not int or run_id < 1
            or not isinstance(sha, str) or not SHA.fullmatch(sha)
            or not isinstance(release_id, str)
            or not re.fullmatch(r"[a-z0-9][a-z0-9-]{2,60}", release_id)
            or type(decision.get("owner_comment_id")) is not int
            or decision["owner_comment_id"] < 1):
        return result
    if (not isinstance(existing_comments, list) or len(existing_comments) >= MAX_COMMENTS
            or any(not isinstance(c, dict) or type(c.get("id")) is not int
                   or not isinstance(c.get("body"), str) for c in existing_comments)):
        return {**result, "reason": "comment_history_incomplete"}
    marker = f"{MARKER_PREFIX}{release_id}:{sha}:{run_id}:{decision['owner_comment_id']} -->"
    if any(marker in c["body"] for c in existing_comments):
        return {**result, "status": "ALREADY_RECORDED",
                "reason": "matching_immutable_marker_found"}
    if any(MARKER_PREFIX in c["body"] for c in existing_comments):
        return {**result, "reason": "conflicting_or_unreviewed_registration"}
    body = (marker + "\nB4 acceptance evidence recorded for review: "
            + "release " + release_id + ", commit " + sha + ", run "
            + str(run_id) + ", owner approval comment "
            + str(decision["owner_comment_id"]) + ". "
            + "Independent verification and browser acceptance must be "
            + "rechecked before any actual GitHub write.")
    return {**result, "status": "READY_FOR_MANUAL_REVIEW",
            "reason": "unique_marker_and_bounded_history", "proposed_body": body}
