"""Independent OFFLINE review of a proposed PR475 post-write receipt.

No API transport, token, secret, nonce creation, mutations, or activation.
Matching reported data never proves trusted origin or authorizes live actions.
"""
import hashlib
from scripts import phase_c_new_trusted_writer as scope

DENY = {"decision": "DENY", "write_allowed": False, "network_allowed": False,
        "retry_allowed": False, "activation_allowed": False}


def review_receipt(receipt, *, candidate_oid):
    """Inspect already-collected evidence; return non-authorizing verdict."""
    refused = dict(DENY, reason="Untrusted or incomplete offline receipt")
    if type(receipt) is not dict or type(candidate_oid) is not str:
        return refused
    if not scope.HEX40.fullmatch(candidate_oid) or candidate_oid == scope.HEAD:
        return refused
    expected_nonce = receipt.get("nonce_ref")
    if (type(expected_nonce) is not str
            or not expected_nonce.startswith("refs/tags/phase-c-once-issue-")
            or not expected_nonce[len("refs/tags/phase-c-once-issue-"):].isdigit()
            or expected_nonce == "refs/tags/phase-c-once-issue-427"):
        return refused
    try:
        corrected = scope.deterministic_candidate(scope.ORIGINAL)
        commit = receipt["candidate_commit"]
        path = receipt["file"]
        pr = receipt["pull_request"]
        branch = receipt["branch"]
        nonce = receipt["nonce"]
        compare = receipt["compare"]
        if not all(type(x) is dict for x in (commit, path, pr, branch, nonce, compare)):
            return refused
        valid = (
            receipt.get("repository") == scope.REPO
            and receipt.get("repository_node_id") == scope.REPO_NODE
            and branch == {"ref": scope.BRANCH, "sha": candidate_oid}
            and nonce == {"ref": expected_nonce, "sha": scope.HEAD, "type": "commit"}
            and pr.get("number") == scope.PR and pr.get("draft") is True
            and pr.get("merged") is False and pr.get("state") == "open"
            and pr.get("head") == candidate_oid
            and commit.get("sha") == candidate_oid
            and commit.get("parents") == [scope.HEAD]
            and compare.get("ahead_by") == 1 and compare.get("behind_by") == 0
            and compare.get("base") == scope.HEAD and compare.get("head") == candidate_oid
            and compare.get("files") == [{
                "path": scope.TARGET, "status": "modified",
                "additions": 1, "deletions": 1,
                "old_blob": scope.OLD_BLOB, "new_blob": scope.NEW_BLOB,
            }]
            and path.get("path") == scope.TARGET
            and path.get("blob") == scope.NEW_BLOB
            and type(path.get("content")) is bytes
            and path["content"] == corrected
            and hashlib.sha256(path["content"]).hexdigest() == scope.NEW_SHA256
        )
    except (KeyError, ValueError, TypeError, AttributeError):
        return refused
    if not valid:
        return refused
    return dict(DENY, decision="OFFLINE_RECEIPT_CONSISTENT",
                reason="Self-consistent untrusted data; not authenticated execution evidence")


def execute(*_args, **_kwargs):
    return dict(DENY, reason="No live execution or retry permitted")
