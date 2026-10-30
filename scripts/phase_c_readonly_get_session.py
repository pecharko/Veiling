"""Bounded, non-authorizing Phase C GitHub GET session (inactive).

Connects the already implemented GET-only adapter and complete sandbox audit.
A caller must construct GitHubReadOnlyClient with an explicitly provided
credential. No CI or workflow calls this module with a live credential.
Injected opener responses, even if correct-shaped, do not prove API provenance.
This session never mutates GitHub and never grants write/retry/merge rights.
"""
import hashlib
import json

from scripts.phase_c_complete_readonly_audit import audit_sandbox_readonly
from scripts.phase_c_github_readonly_client import (
    GitHubReadError, GitHubReadOnlyClient,
)

MAX_GET_REQUESTS = 20
SCOPE = "pecharko/Veiling:phase-c-sandbox"
NO_AUTHORITY = {
    "write_allowed": False,
    "retry_allowed": False,
    "merge_allowed": False,
    "dispatch_allowed": False,
    "production_authorized": False,
    "ready_for_activation": False,
}


def audit_phase_c_get_session(client, pr_number, issue_number, comment_id,
                              auth, candidate_sha, pinned_main_sha):
    """Return a bounded, redacted, deterministic advisory-only audit record.

    No request results, approval comments, bearer tokens, URLs with arbitrary
    parameters, GraphQL mutation previews or candidate file content are copied
    into the output. A report digest is tamper-evidence for the returned bytes,
    NOT proof that responses were GitHub-authenticated or a write approval.
    """
    observed = []
    reason = "EVIDENCE_DENIED"
    decision = "DENY"
    safe_evidence = {}

    if type(client) is GitHubReadOnlyClient:
        def guarded_get(path):
            if len(observed) >= MAX_GET_REQUESTS:
                raise GitHubReadError("Read-only request budget exceeded")
            observed.append(path)
            return client.get(path)

        try:
            verdict = audit_sandbox_readonly(
                guarded_get, pr_number, issue_number, comment_id, auth,
                candidate_sha, pinned_main_sha,
            )
            if (type(verdict) is dict and
                    verdict.get("decision") == "READ_ONLY_REVIEW_REQUIRED" and
                    all(verdict.get(k) is False for k in NO_AUTHORITY) and
                    verdict.get("nonce_status") == "404_NOT_PROOF_OF_ABSENCE"):
                decision = "READ_ONLY_REVIEW_REQUIRED"
                reason = "MATCHING_READBACKS_NOT_WRITE_AUTHORIZATION"
                safe_evidence = {
                    "pinned_main_sha": verdict["pinned_main_sha"],
                    "approved_branch_sha": verdict["approved_branch_sha"],
                    "candidate_sha": verdict["candidate_sha"],
                    "nonce_status": verdict["nonce_status"],
                }
            else:
                reason = "EVIDENCE_DENIED"
        except (GitHubReadError, ValueError, TypeError, KeyError, OSError):
            reason = "READ_ONLY_TRANSPORT_OR_VALIDATION_ERROR"

    report = {
        "decision": decision,
        "reason_code": reason,
        "scope": SCOPE,
        "get_request_count": len(observed),
        "git_ref_updates_executed": 0,
        **NO_AUTHORITY,
        **safe_evidence,
    }
    encoded = json.dumps(
        report, sort_keys=True, separators=(",", ":"), ensure_ascii=True
    ).encode("ascii")
    report["report_sha256"] = hashlib.sha256(encoded).hexdigest()
    return report


def audit_repair_get_session(client, request):
    """Acquire two bounded, GET-only snapshots for C5 source review.

    Unlike a submitted payload, these reads are made by an explicitly
    constructed GitHubReadOnlyClient with a fixed HTTPS origin. An injected
    opener can still fake data. Two consistent passes DO NOT prove atomic
    consistency, token provenance, effective permissions or write approval.
    No use of GitHub Actions secrets, token generation, POST or retries.
    """
    from scripts.development_ci_write_gate import (
        assess_repair_write, review_github_readbacks,
    )
    no_authority = {
        "can_write": False,
        "can_merge": False,
        "can_dispatch": False,
        "production_authorized": False,
        "owner_identity_verified": False,
        "nonce_reserved": False,
        "source_provenance_authenticated": False,
        "write_allowed": False,
        "retry_allowed": False,
    }
    result = {
        **no_authority,
        "decision": "DENY",
        "reason_code": "READ_ONLY_EVIDENCE_DENIED",
        "readback_structurally_consistent": False,
        "get_request_count": 0,
        "git_ref_updates_executed": 0,
        "scope": "pecharko/Veiling:phase-c-repair-readonly",
    }
    if type(client) is not GitHubReadOnlyClient or not isinstance(request, dict):
        return result
    if assess_repair_write(request).get("decision") != "ELIGIBLE_FOR_SEPARATE_WRITE_APPROVAL":
        return result

    root = "/repos/pecharko/Veiling"
    pr_path = root + "/pulls/" + str(request["pr"])
    branch_path = root + "/git/ref/heads/" + request["branch"]
    file_path = (root + "/contents/" + request["path"] + "?ref="
                 + request["expected_head_sha"])
    observed = 0
    reports = []
    try:
        for _ in range(2):
            pr = client.get(pr_path)
            observed += 1
            branch = client.get(branch_path)
            observed += 1
            blob = client.get(file_path)
            observed += 1
            review = review_github_readbacks(request, pr, branch, blob)
            if (review.get("decision") != "PROPOSED_ONLY"
                    or review.get("readback_structurally_consistent") is not True
                    or any(review.get(k) is not False for k in no_authority
                           if k in review)):
                raise GitHubReadError("Readback binding refused")
            reports.append(review)
        if reports[0] != reports[1]:
            raise GitHubReadError("Review changed between readbacks")
        safe = {
            "decision": "READ_ONLY_REVIEW",
            "reason_code": "TWO_CONSISTENT_GET_PASSES_NOT_AUTHORIZATION",
            "readback_structurally_consistent": True,
            "review_sha256": reports[0]["review_sha256"],
            "original_blob_sha": reports[0]["original_blob_sha"],
            "candidate_blob_sha": reports[0]["candidate_blob_sha"],
            "candidate_sha256": reports[0]["candidate_sha256"],
            "head_sha": reports[0]["head_sha"],
            "pr": reports[0]["pr"],
            "path": reports[0]["path"],
        }
        result.update(safe)
    except (GitHubReadError, KeyError, TypeError, ValueError, OSError):
        pass  # Fail closed; no request retries or state mutations.
    result["get_request_count"] = observed
    digest = json.dumps(result, sort_keys=True,
                        separators=(",", ":"), ensure_ascii=True).encode("ascii")
    result["report_sha256"] = hashlib.sha256(digest).hexdigest()
    return result
