#!/usr/bin/env python3
"""B4 bounded, read-only GitHub owner/issue evidence fetch.

GET only for the exact repository, issue and owner-comment identity. OVH
readback must be independently verified elsewhere. Does not accept releases.
"""
from controller_evidence_collector import get_json
from controller_b4_owner_evidence_binding import evaluate
from controller_b4_registration_plan import plan

MAX_COMMENTS = 100


def collect_owner_evidence(issue_number, comment_id, token, *,
                           read_json=get_json):
    if (type(issue_number) is not int or issue_number <= 0 or
            type(comment_id) is not int or comment_id <= 0 or
            not isinstance(token, str) or not token):
        raise ValueError("Invalid immutable issue/comment identity")
    issue = read_json("/issues/" + str(issue_number), token)
    comment = read_json("/issues/comments/" + str(comment_id), token)
    if (not isinstance(issue, dict) or not isinstance(comment, dict) or
            issue.get("number") != issue_number or
            comment.get("id") != comment_id or
            comment.get("issue_url") !=
              "https://api.github.com/repos/pecharko/Veiling/issues/" + str(issue_number)):
        raise ValueError("Unbound GitHub issue/comment")
    return issue, comment


def review_owner_with_github(*, issue_number, comment_id, token, expected_owner,
                             evidence, ovh_attestation, run_id, release_sha,
                             release_id, read_json=get_json):
    """GET-and-review only; caller-provided OVH status is NOT independent proof."""
    issue, comment = collect_owner_evidence(
        issue_number, comment_id, token, read_json=read_json)
    return evaluate(issue=issue, comment=comment, expected_owner=expected_owner,
                    evidence=evidence, ovh_attestation=ovh_attestation,
                    run_id=run_id, release_sha=release_sha, release_id=release_id)


def collect_comment_history(issue_number, token, *, read_json=get_json):
    """Bound history to one exact page; fail on 100 rows or malformed results."""
    if type(issue_number) is not int or issue_number <= 0:
        raise ValueError("Invalid issue identity")
    comments = read_json("/issues/" + str(issue_number) + "/comments?per_page=100", token)
    if (not isinstance(comments, list) or len(comments) >= MAX_COMMENTS or
            any(not isinstance(c, dict) or type(c.get("id")) is not int
                or not isinstance(c.get("body"), str) or
                c.get("issue_url") !=
                "https://api.github.com/repos/pecharko/Veiling/issues/" + str(issue_number)
                for c in comments)):
        raise ValueError("Incomplete or unbound issue comments")
    return comments


def review_registration(*, issue_number, comment_id, token, expected_owner,
                        evidence, ovh_attestation, run_id, release_sha, release_id,
                        read_json=get_json):
    """One read-only integration of owner proof and bounded replay scan.

    NEVER authorizes a write. A caller-supplied OVH assertion is not trusted
    provenance; this function only produces a manual-review proposal.
    """
    decision = review_owner_with_github(
        issue_number=issue_number, comment_id=comment_id, token=token,
        expected_owner=expected_owner, evidence=evidence,
        ovh_attestation=ovh_attestation, run_id=run_id,
        release_sha=release_sha, release_id=release_id, read_json=read_json)
    if decision.get("status") != "READY_FOR_REVIEW":
        return {"status": "BLOCKED", "reason": "owner_or_ovh_review_incomplete",
                "write_permitted": False, "write_executed": False,
                "proposed_body": None}
    comments = collect_comment_history(issue_number, token, read_json=read_json)
    result = plan(decision, issue_number=issue_number,
                  existing_comments=comments)
    # No output can ever grant annotation authority; trusted independent
    # verification and serialized registration remain future work.
    return result
