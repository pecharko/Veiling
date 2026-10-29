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
