"""Phase C2 immutable owner-approval contract; OFFLINE ONLY.

No credentials, network operations or write entrypoint. Accepting matching
metadata never constitutes a right to mutate refs.
"""
import re

REPOSITORY = "pecharko/Veiling"
HEAD = "2595a63c6cd575dbbb7f29f293ccccaccc140553"
BRANCH = "refs/heads/test/phase-c-writer-fixture-20261029"
NONCE = "refs/tags/phase-c-once-issue-427"
TARGET = "tests/phase_c_sandbox_test.php"
_SHA = re.compile(r"^[0-9a-f]{40}$")
_REQUIRED = frozenset({"repository", "pr", "expected_head", "target_ref",
                        "nonce_ref", "candidate_oid", "candidate_parent",
                        "candidate_paths", "issue", "approval_id",
                        "owner", "authorized_operation"})


def review_owner_scope(record):
    denied = {"decision": "DENY", "can_execute": False,
              "may_change_permissions": False, "may_retry": False,
              "may_deploy": False}
    if type(record) is not dict or set(record) != _REQUIRED:
        return dict(denied, reason="Authorization record schema mismatch")
    if not (
        record["repository"] == REPOSITORY
        and type(record["pr"]) is int and record["pr"] == 426
        and record["expected_head"] == HEAD
        and record["target_ref"] == BRANCH
        and record["nonce_ref"] == NONCE
        and type(record["issue"]) is int and record["issue"] == 427
        and record["candidate_parent"] == HEAD
        and type(record["candidate_paths"]) is list
        and record["candidate_paths"] == [TARGET]
        and type(record["candidate_oid"]) is str
        and bool(_SHA.fullmatch(record["candidate_oid"]))
        and record["candidate_oid"] != HEAD
        and type(record["approval_id"]) is str
        and bool(re.fullmatch(r"[1-9][0-9]*", record["approval_id"]))
        and record["owner"] == "pecharko"
        and record["authorized_operation"] == "atomic-nonce-and-sandbox-cas"
    ):
        return dict(denied, reason="Authorization record is not exact immutable scope")
    return dict(denied, decision="REVIEW_ONLY",
                reason="Metadata structurally valid; owner identity and approval unverified")
