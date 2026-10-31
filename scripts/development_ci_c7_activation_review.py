#!/usr/bin/env python3
"""Phase C7 future writer preactivation review. Pure offline; never executes.

The supplied GitHub-shaped values are UNTRUSTED and MUST NOT be regarded as
authenticated responses, owner consent, permission to create a nonce or write.
No GitHub client, token, HTTP, workflow dispatch, branch update or retry.
"""
import hashlib
import json
import re

REPOSITORY = "pecharko/Veiling"
OWNER_ID = 343879284
APP_ID = 5526516
APP_SLUG = "41lokeren-phase-c-writer"
INSTALLATION_ID = 176547610
BRANCH_RULESET = 26113993
TAG_RULESET = 26113833
HEX40 = re.compile(r"^[0-9a-f]{40}$")
HEX64 = re.compile(r"^[0-9a-f]{64}$")
PATH = re.compile(r"^tests/[A-Za-z0-9_-]+_test\.php$")
NO_AUTHORITY = {
    "can_write": False,
    "write_allowed": False,
    "can_merge": False,
    "can_dispatch": False,
    "retry_allowed": False,
    "production_authorized": False,
    "activation_allowed": False,
    "owner_identity_verified": False,
    "owner_live_write_approved": False,
    "source_provenance_authenticated": False,
    "app_token_authenticated": False,
    "nonce_reserved": False,
    "git_ref_updates_executed": 0,
}


def _digest(value):
    serialized = json.dumps(
        value, sort_keys=True, separators=(",", ":"), ensure_ascii=True,
    ).encode("ascii")
    return hashlib.sha256(serialized).hexdigest()


def review_activation_boundary(
    source, issue, comment, app, installation, repositories,
    branch_rules, tag_rules, nonce_state,
):
    """Cross-check preactivation records, returning NON-EXECUTABLE evidence.

    A matching comment is a *review-only* acknowledgment, not authorization to
    activate a writer. A nonce absence or effective ruleset cannot be
    authenticated from caller-supplied JSON. No runtime write path exists here.
    """
    report = {
        **NO_AUTHORITY,
        "decision": "DENY",
        "reason_code": "MISSING_OR_INCONSISTENT_REVIEW_EVIDENCE",
        "scope": REPOSITORY + ":future-phase-c-repair",
        "blocking_requirements": [
            "independently_authenticated_fresh_GitHub_and_App_evidence",
            "separate_exact_owner_live_writer_authorization",
            "new_durable_atomic_nonce_reservation_and_single_CAS",
            "independent_post_write_readback",
        ],
    }
    def finish():
        report["report_sha256"] = _digest(report)
        return report

    if any(type(x) is not dict for x in (
        source, issue, comment, app, installation, repositories,
        branch_rules, tag_rules,
    )):
        return finish()
    if nonce_state != "NOT_PROVEN_ABSENT":
        # In particular, a known occupied nonce makes replay impossible.
        return finish()

    if (source.get("decision") != "READ_ONLY_REVIEW"
            or source.get("reason_code") !=
               "TWO_CONSISTENT_GET_PASSES_NOT_WRITE_AUTHORIZATION"
            or source.get("readback_structurally_consistent") is not True
            or type(source.get("get_request_count")) is not int
            or source["get_request_count"] != 6
            or type(source.get("pr")) is not int
            or source["pr"] <= 0
            or not isinstance(source.get("path"), str)
            or not PATH.fullmatch(source["path"])
            or any(not isinstance(source.get(k), str)
                   or not pattern.fullmatch(source[k])
                   for k, pattern in (
                       ("head_sha", HEX40), ("original_blob_sha", HEX40),
                       ("candidate_blob_sha", HEX40),
                       ("candidate_sha256", HEX64), ("review_sha256", HEX64),
                       ("report_sha256", HEX64)))
            or any(source.get(k) is not False for k in NO_AUTHORITY
                   if k in source and k != "git_ref_updates_executed")
            or source.get("git_ref_updates_executed") != 0):
        return finish()
    # The digest protects the supplied packet's canonical bytes against
    # accidental modification; it does NOT authenticate GitHub provenance.
    source_without_digest = dict(source)
    source_digest = source_without_digest.pop("report_sha256")
    if _digest(source_without_digest) != source_digest:
        return finish()

    number = issue.get("number")
    user = issue.get("user")
    issuer = comment.get("user")
    if (type(number) is not int or number <= 427
            or number == source["pr"]
            or issue.get("state") != "open"
            or issue.get("pull_request") is not None
            or issue.get("repository_url") != "https://api.github.com/repos/" + REPOSITORY
            or issue.get("url") !=
               "https://api.github.com/repos/" + REPOSITORY +
               "/issues/" + str(number)
            or type(user) is not dict or type(issuer) is not dict
            or type(user.get("id")) is not int or user["id"] != OWNER_ID
            or type(issuer.get("id")) is not int or issuer["id"] != OWNER_ID
            or comment.get("issue_url") != issue["url"]
            or type(comment.get("id")) is not int or comment["id"] <= 0):
        return finish()

    new_nonce = "refs/tags/phase-c-once-issue-" + str(number)
    exact_body = (
        "PHASE-C-REPAIR-REVIEW-ONLY"
        + " pr=" + str(source["pr"])
        + " head=" + source["head_sha"]
        + " path=" + source["path"]
        + " blob=" + source["original_blob_sha"]
        + " candidate=" + source["candidate_blob_sha"]
        + " review=" + source["review_sha256"]
        + " nonce=" + new_nonce
    )
    if comment.get("body") != exact_body:
        return finish()

    if (type(app.get("id")) is not int or app["id"] != APP_ID
            or app.get("slug") != APP_SLUG
            or type(installation.get("id")) is not int
            or installation["id"] != INSTALLATION_ID
            or installation.get("app_slug") != APP_SLUG
            or installation.get("repository_selection") != "selected"
            or installation.get("permissions") !=
               {"contents": "write", "metadata": "read"}
            or type(repositories.get("total_count")) is not int
            or repositories["total_count"] != 1
            or type(repositories.get("repositories")) is not list
            or len(repositories["repositories"]) != 1
            or type(repositories["repositories"][0]) is not dict
            or repositories["repositories"][0].get("full_name") != REPOSITORY):
        return finish()
    # Branch protection and tag protection use different GitHub rules:
    # a branch denies non-fast-forward and deletion, while an immutable
    # nonce tag denies update and deletion. Do NOT weaken either contract.
    for config, rule_id, target, required in (
        (branch_rules, BRANCH_RULESET, "branch",
         {"deletion", "non_fast_forward"}),
        (tag_rules, TAG_RULESET, "tag",
         {"deletion", "update"}),
    ):
        entries = config.get("rules")
        if (type(config.get("id")) is not int
                or config["id"] != rule_id
                or config.get("target") != target
                or config.get("enforcement") != "active"
                or type(config.get("bypass_actors")) is not list
                or config["bypass_actors"] != []
                or type(entries) is not list
                or not required.issubset(
                    {x.get("type") for x in entries if type(x) is dict})):
            return finish()

    report.update({
        "decision": "READ_ONLY_SECURITY_REVIEW",
        "reason_code": "SHAPES_MATCH_BUT_IDENTITY_NONCE_AND_ATOMICITY_NOT_PROVEN",
        "pr": source["pr"],
        "head_sha": source["head_sha"],
        "path": source["path"],
        "review_sha256": source["review_sha256"],
        "source_report_sha256": source_digest,
        "review_comment_id": comment["id"],
        "review_issue": number,
        "proposed_nonce_ref": new_nonce,
        "review_records_structurally_consistent": True,
        "effective_ruleset_coverage_proven": False,
        "nonce_absence_proven": False,
    })
    return finish()


if __name__ == "__main__":
    raise SystemExit("Offline review only: no writer or activation entrypoint")
