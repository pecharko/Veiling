#!/usr/bin/env python3
"""Phase C NEW sandbox trusted-writer core: inert, no live transport.

All packets are review-only. A later separately reviewed activation change
and exact owner authorization are mandatory for any live GitHub write.
"""
import copy
import hashlib
import json
import re
import sys

REPO = "pecharko/Veiling"
REPO_NODE = "R_kgDOVNLxvg"
OWNER = {"login": "pecharko", "id": 343879284}
PR = 475
BASE_SHA = "3a0d61c07832df05eba4f377f446fd9e98b4f9be"
BRANCH = "refs/heads/test/phase-c-new-isolated-20261030"
HEAD = "2ee5419b4642b2108414c8b9d4f193298004b23a"
TARGET = "tests/phase_c_new_repair_test.php"
OLD_BLOB = "76fda06da4013582f240ffc7c35ee94dbb502e67"
NEW_BLOB = "3f48693b3092785554192ad766940565c9435a17"
NEW_SHA256 = "b743ff7e7006659676f24bf7626d95cb8a75a52b4c681e74a2629924d221059d"
BRANCH_RULESET_ID = 26113993
TAG_RULESET_ID = 26113833
TAG_PATTERN = "refs/tags/phase-c-once-issue-*"
BRANCH_RULES = [
    "refs/heads/test/phase-c-writer-fixture-20261029",
    BRANCH,
]
ORIGINAL = (b"<?php\nfunction phase_c_new_repair_fixture(): bool {\n"
            b"    return flase;\n}\n")
HEX40 = re.compile(r"[0-9a-f]{40}\Z")
DISABLED = {
    "decision": "DENY", "network_allowed": False, "write_allowed": False,
    "retry_allowed": False, "activation_allowed": False,
    "merge_allowed": False, "production_allowed": False,
}


def git_blob_id(data):
    """Compute SHA-1 of a Git blob locally, without creating a Git object."""
    return hashlib.sha1(b"blob " + str(len(data)).encode("ascii") + b"\0" + data).hexdigest()


def deterministic_candidate(blob):
    """Accept only exact original bytes and one precise typo correction."""
    if type(blob) is not bytes or blob != ORIGINAL or blob.count(b"return flase;") != 1:
        raise ValueError("Fixture content or typo count drift")
    if git_blob_id(blob) != OLD_BLOB:
        raise ValueError("Original blob digest mismatch")
    fixed = blob.replace(b"return flase;", b"return false;", 1)
    if (git_blob_id(fixed) != NEW_BLOB
            or hashlib.sha256(fixed).hexdigest() != NEW_SHA256):
        raise ValueError("Candidate digest mismatch")
    return fixed


def _exact_rule(rule, rule_id, target, included, required):
    if type(rule) is not dict or rule.get("id") != rule_id:
        return False
    conditions = rule.get("conditions")
    if type(conditions) is not dict:
        return False
    ref = conditions.get("ref_name")
    actual = rule.get("rules")
    return (rule.get("source") == REPO and rule.get("target") == target
            and rule.get("enforcement") == "active"
            and rule.get("bypass_actors") == []
            and rule.get("current_user_can_bypass") == "never"
            and type(ref) is dict and ref.get("include") == included
            and ref.get("exclude") == []
            and type(actual) is list and len(actual) == len(required)
            and all(type(r) is dict for r in actual)
            and {r.get("type") for r in actual} == required)


def _owner(record):
    return (type(record) is dict and type(record.get("user")) is dict
            and record["user"].get("login") == OWNER["login"]
            and type(record["user"].get("id")) is int
            and record["user"]["id"] == OWNER["id"])


def owner_approval_phrase(issue, worker_sha, candidate_oid):
    """Proposed future comment text; generating it never grants permission."""
    return ("PHASE-C-NEW-ONCE repo=" + REPO + " pr=475 issue=" + str(issue)
            + " old=" + HEAD + " new=" + candidate_oid
            + " blob=" + OLD_BLOB + " target=" + TARGET
            + " nonce=refs/tags/phase-c-once-issue-" + str(issue)
            + " worker=" + worker_sha)


def _snapshot_ok(s, candidate_oid):
    if type(s) is not dict:
        return False
    pr, branch, candidate = s.get("pr"), s.get("branch"), s.get("candidate")
    if any(type(x) is not dict for x in (pr, branch, candidate)):
        return False
    expected_files = [{
        "path": TARGET, "status": "modified",
        "old_blob": OLD_BLOB, "new_blob": NEW_BLOB,
        "additions": 1, "deletions": 1,
    }]
    return (
        s.get("repository") == REPO and s.get("repository_node_id") == REPO_NODE
        and pr.get("number") == PR and pr.get("state") == "open"
        and pr.get("draft") is True and pr.get("merged") is False
        and pr.get("base_ref") == "main" and pr.get("base_sha") == BASE_SHA
        and pr.get("head_ref") == BRANCH and pr.get("head_sha") == HEAD
        and pr.get("head_repo") == REPO and pr.get("base_repo") == REPO
        and branch == {"ref": BRANCH, "sha": HEAD}
        and s.get("source_blob") == {"sha": OLD_BLOB, "data": ORIGINAL}
        and candidate.get("sha") == candidate_oid
        and candidate.get("parents") == [HEAD]
        and candidate.get("files") == expected_files
        and candidate.get("content") == deterministic_candidate(ORIGINAL)
        and s.get("nonce_status") == 404
        and _exact_rule(s.get("branch_rule"), BRANCH_RULESET_ID,
                        "branch", BRANCH_RULES, {"deletion", "non_fast_forward"})
        and _exact_rule(s.get("tag_rule"), TAG_RULESET_ID,
                        "tag", [TAG_PATTERN], {"update", "deletion"})
    )


def make_review_packet(snapshot, issue_record, comment_record, *,
                       issue_number, comment_id, worker_sha, candidate_oid):
    """Return an INERT envelope; caller-provided data is NEVER authorization."""
    deny = dict(DISABLED, reason="Incomplete or untrusted sandbox evidence")
    if (type(issue_number) is not int or issue_number <= PR
            or issue_number == 427
            or type(comment_id) is not int or comment_id <= 0
            or type(worker_sha) is not str or not HEX40.fullmatch(worker_sha)
            or type(candidate_oid) is not str or not HEX40.fullmatch(candidate_oid)
            or candidate_oid == HEAD):
        return deny
    try:
        if not _snapshot_ok(snapshot, candidate_oid):
            return deny
    except (TypeError, ValueError, AttributeError):
        return deny
    nonce = "refs/tags/phase-c-once-issue-" + str(issue_number)
    expected_issue = ("PHASE-C-NEW-PLAN pr=475 issue=" + str(issue_number)
                      + " head=" + HEAD + " blob=" + OLD_BLOB)
    expected_issue_url = ("https://api.github.com/repos/" + REPO
                          + "/issues/" + str(issue_number))
    if (not _owner(issue_record) or not _owner(comment_record)
            or issue_record.get("number") != issue_number
            or issue_record.get("state") != "open"
            or issue_record.get("body") != expected_issue
            or comment_record.get("id") != comment_id
            or comment_record.get("issue_url") != expected_issue_url
            or comment_record.get("body") != owner_approval_phrase(
                issue_number, worker_sha, candidate_oid)):
        return deny
    updates = [
        {"name": nonce, "beforeOid": "0" * 40, "afterOid": HEAD, "force": False},
        {"name": BRANCH, "beforeOid": HEAD, "afterOid": candidate_oid, "force": False},
    ]
    payload = {
        "query": "mutation PhaseCNewAtomicRefs($input: UpdateRefsInput!) { "
                 "updateRefs(input: $input) { clientMutationId } }",
        "variables": {"input": {
            "repositoryId": REPO_NODE,
            "clientMutationId": "phase-c-new-" + str(issue_number)
                                + "-" + str(comment_id) + "-" + HEAD[:12],
            "refUpdates": updates,
        }},
    }
    return dict(DISABLED, decision="REVIEW_ONLY",
                reason="Offline shape check is not authenticated permission",
                nonce_ref=nonce, candidate_oid=candidate_oid, payload=payload)


def simulate_atomic(packet, refs, *, fault=None):
    """In-memory CAS on a deep copy: NEVER a GitHub or filesystem mutation."""
    refused = dict(DISABLED, reason="Offline CAS refused")
    if type(refs) is not dict:
        return refused, {}
    if type(packet) is not dict or packet.get("decision") != "REVIEW_ONLY":
        return refused, copy.deepcopy(refs)
    if fault not in (None, "conflict", "timeout"):
        return refused, copy.deepcopy(refs)
    inp = packet.get("payload", {}).get("variables", {}).get("input", {})
    updates = inp.get("refUpdates")
    nonce, cand = packet.get("nonce_ref"), packet.get("candidate_oid")
    valid = (
        type(updates) is list and len(updates) == 2
        and type(nonce) is str
        and bool(re.fullmatch(r"refs/tags/phase-c-once-issue-[1-9][0-9]*", nonce))
        and nonce != "refs/tags/phase-c-once-issue-427"
        and type(cand) is str and bool(HEX40.fullmatch(cand))
        and updates == [
            {"name": nonce, "beforeOid": "0" * 40, "afterOid": HEAD, "force": False},
            {"name": BRANCH, "beforeOid": HEAD, "afterOid": cand, "force": False},
        ]
        and inp.get("repositoryId") == REPO_NODE
    )
    if not valid or refs.get(BRANCH) != HEAD or nonce in refs:
        return refused, copy.deepcopy(refs)
    if fault in ("conflict", "timeout"):
        return dict(DISABLED, decision="AMBIGUOUS_STOP_NO_RETRY",
                    reason="Conflict or uncertainty is terminal"), copy.deepcopy(refs)
    new_refs = copy.deepcopy(refs)
    new_refs[nonce] = HEAD
    new_refs[BRANCH] = cand
    return dict(DISABLED, decision="SIMULATED_ONLY",
                reason="In-memory copy; no GitHub ref changed"), new_refs


def execute_one_shot(*_args, **_kwargs):
    """No activation switch, token, HTTP/GraphQL sender or nonce creation."""
    return dict(DISABLED, reason="Trusted live writer NOT installed or authorized")


def main(argv=None):
    args = sys.argv[1:] if argv is None else argv
    print(json.dumps(execute_one_shot(), sort_keys=True))
    return 2 if args else 0


if __name__ == "__main__":
    sys.exit(main())
