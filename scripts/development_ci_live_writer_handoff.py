#!/usr/bin/env python3
"""Reusable Phase C future-branch protection and postwrite evidence checks.

Pure offline review only. Never acquires tokens, calls GitHub, writes refs,
authorizes mutations, retries ambiguous outcomes or touches the C2 fixture.
All payloads are caller-supplied/untrusted; a matching shape is not proof
of origin, owner permission, effective ruleset application, or atomicity.
"""
import base64
import binascii
import hashlib
import re

REPOSITORY = "pecharko/Veiling"
BRANCH_PREFIX = "refs/heads/"
BRANCH = re.compile(r"^refs/heads/test/phase-c-[a-z0-9-]{1,40}$")
NONCE = re.compile(r"^refs/tags/phase-c-once-issue-([1-9][0-9]*)$")
SHA = re.compile(r"^[a-f0-9]{40}$")
PATH = re.compile(r"^tests/[A-Za-z0-9_-]+_test\.php$")
OLD_FIXTURE = "refs/heads/test/phase-c-writer-fixture-20261029"
OLD_NONCE = "refs/tags/phase-c-once-issue-427"
MAX_CONTENT = 2048
NO_AUTHORITY = {
    "write_allowed": False,
    "can_write": False,
    "can_merge": False,
    "can_dispatch": False,
    "retry_allowed": False,
    "activation_allowed": False,
    "production_authorized": False,
    "nonce_reserved": False,
    "owner_identity_verified": False,
    "app_token_authenticated": False,
    "source_provenance_authenticated": False,
    "git_ref_updates_executed": 0,
}


def _denied(reason):
    return dict(NO_AUTHORITY, decision="DENY", reason_code=reason)


def _valid_scope(proposal):
    if type(proposal) is not dict:
        return False
    branch = proposal.get("branch_ref")
    nonce = proposal.get("nonce_ref")
    if (type(branch) is not str or not BRANCH.fullmatch(branch)
            or branch == OLD_FIXTURE
            or type(nonce) is not str or not NONCE.fullmatch(nonce)
            or nonce == OLD_NONCE or int(NONCE.fullmatch(nonce).group(1)) <= 427
            or proposal.get("repository") != REPOSITORY
            or type(proposal.get("pr")) is not int or proposal["pr"] <= 0
            or type(proposal.get("path")) is not str
            or not PATH.fullmatch(proposal["path"])):
        return False
    for key in ("before_sha", "after_sha", "original_blob_sha",
                "candidate_blob_sha"):
        if type(proposal.get(key)) is not str or not SHA.fullmatch(proposal[key]):
            return False
    return (proposal["before_sha"] != proposal["after_sha"]
            and proposal["original_blob_sha"] != proposal["candidate_blob_sha"]
            and type(proposal.get("candidate_sha256")) is str
            and re.fullmatch(r"[a-f0-9]{64}", proposal["candidate_sha256"]) is not None)


def _rule_matches(record, rule_id, target, include, required):
    if type(record) is not dict:
        return False
    conditions = record.get("conditions")
    if type(conditions) is not dict:
        return False
    ref_name = conditions.get("ref_name")
    if type(ref_name) is not dict:
        return False
    rules = record.get("rules")
    return (
        type(record.get("id")) is int and record["id"] == rule_id
        and record.get("source") == REPOSITORY
        and record.get("target") == target
        and record.get("enforcement") == "active"
        and type(record.get("bypass_actors")) is list
        and record["bypass_actors"] == []
        and type(ref_name.get("include")) is list
        and ref_name["include"] == [include]
        and type(ref_name.get("exclude")) is list
        and ref_name["exclude"] == []
        and type(rules) is list
        and required.issubset(
            {x.get("type") for x in rules if type(x) is dict}
        )
    )


def inspect_target_rule_coverage(proposal, branch_rule, tag_rule):
    """Review submitted conditions for *this exact new branch*, not the old C2.

    The check deliberately requires an explicit branch include rather than a
    broad wildcard. No ruleset can be changed by this module. Even if the
    shape matches, caller-supplied JSON is NOT authenticated live coverage.
    """
    if not _valid_scope(proposal):
        return _denied("INVALID_NEW_SANDBOX_SCOPE")
    if not _rule_matches(
        branch_rule, 26113993, "branch", proposal["branch_ref"],
        {"deletion", "non_fast_forward"},
    ):
        return _denied("NEW_BRANCH_RULE_COVERAGE_NOT_ESTABLISHED")
    if not _rule_matches(
        tag_rule, 26113833, "tag",
        "refs/tags/phase-c-once-issue-*", {"update", "deletion"},
    ):
        return _denied("NONCE_TAG_RULE_COVERAGE_NOT_ESTABLISHED")
    return dict(
        NO_AUTHORITY,
        decision="RULE_SHAPES_MATCH_REVIEW_ONLY",
        branch_rule_shape_consistent=True,
        nonce_rule_shape_consistent=True,
        effective_ruleset_coverage_authenticated=False,
        branch_ref=proposal["branch_ref"],
        nonce_ref=proposal["nonce_ref"],
        reason_code="LIVE_RULE_READBACK_AND_OWNER_APPROVAL_STILL_REQUIRED",
    )


def inspect_future_postwrite_readback(proposal, branch, nonce, commit, file):
    """Validate one exactly scoped supplied GitHub after-state (offline only).

    Matches a single-parent, single-file corrected commit and the new nonce
    retaining the original branch head, but does not independently authenticate
    API origin, exact GraphQL calls, nonce reservation or atomic update.
    """
    if not _valid_scope(proposal):
        return _denied("INVALID_NEW_SANDBOX_SCOPE")
    if any(type(x) is not dict for x in (branch, nonce, commit, file)):
        return _denied("MISSING_POSTWRITE_READBACK")
    b, n = branch.get("object"), nonce.get("object")
    if (type(b) is not dict or type(n) is not dict
            or branch.get("ref") != proposal["branch_ref"]
            or b.get("type") != "commit"
            or b.get("sha") != proposal["after_sha"]
            or nonce.get("ref") != proposal["nonce_ref"]
            or n.get("type") != "commit"
            or n.get("sha") != proposal["before_sha"]):
        return _denied("REF_OR_NONCE_READBACK_MISMATCH")
    parents, files = commit.get("parents"), commit.get("files")
    if (commit.get("sha") != proposal["after_sha"]
            or type(parents) is not list or len(parents) != 1
            or type(parents[0]) is not dict
            or parents[0].get("sha") != proposal["before_sha"]
            or type(files) is not list or len(files) != 1
            or type(files[0]) is not dict
            or files[0].get("filename") != proposal["path"]
            or files[0].get("status") != "modified"
            or files[0].get("sha") != proposal["candidate_blob_sha"]):
        return _denied("COMMIT_PARENT_OR_CHANGED_FILES_MISMATCH")
    raw = file.get("content")
    if (file.get("path") != proposal["path"]
            or file.get("type") != "file"
            or file.get("encoding") != "base64"
            or file.get("sha") != proposal["candidate_blob_sha"]
            or type(file.get("size")) is not int
            or not 0 < file["size"] <= MAX_CONTENT
            or type(raw) is not str or len(raw) > 8192):
        return _denied("CANDIDATE_BLOB_READBACK_MISMATCH")
    try:
        decoded = base64.b64decode(raw.replace("\n", ""), validate=True)
    except (ValueError, binascii.Error):
        return _denied("INVALID_POSTWRITE_BLOB_ENCODING")
    if (len(decoded) != file["size"]
            or len(decoded) > MAX_CONTENT
            or hashlib.sha1(
                b"blob " + str(len(decoded)).encode("ascii") + b"\0" + decoded
            ).hexdigest() != proposal["candidate_blob_sha"]
            or hashlib.sha256(decoded).hexdigest() != proposal["candidate_sha256"]):
        return _denied("POSTWRITE_CONTENT_DIGEST_MISMATCH")
    return dict(
        NO_AUTHORITY,
        decision="READBACK_SHAPE_MATCH_ONLY",
        reason_code="POSTWRITE_REVIEW_NOT_ATOMICITY_OR_PERMISSION_PROOF",
        branch_ref=proposal["branch_ref"],
        nonce_ref=proposal["nonce_ref"],
        after_sha=proposal["after_sha"],
        candidate_blob_sha=proposal["candidate_blob_sha"],
        postwrite_readback_authenticated=False,
    )


def execute_one_shot(*_args, **_kwargs):
    return dict(_denied("LIVE_WRITER_NOT_IMPLEMENTED"), operations=[])


if __name__ == "__main__":
    raise SystemExit("Import-only read-only checks; live writer not implemented")
