"""Complete Phase C read-only sandbox evidence audit (inactive).

The audit combines owner-approved candidate checks with two bounded readbacks
of the pinned main ref, sandbox branch and issue-scoped nonce tag. It NEVER
reserves a nonce, updates a ref, retries a write, dispatches or grants authority.
The injected api_get is NOT credential provenance and a tag 404 is NOT proof
that a future writer can create that ref.
"""
import re

from scripts.phase_c_readonly_candidate_verifier import verify_candidate_readonly
from scripts.phase_c_atomic_ref_reconciliation import _commit_ref
from scripts.phase_c_github_readonly_client import (
    ROOT, GitHubReadError, MissingNonceRef,
)

SHA = re.compile(r"^[0-9a-f]{40}$")
BRANCH = re.compile(r"^test/phase-c-[a-z0-9-]{1,40}$")


def audit_sandbox_readonly(api_get, pr_number, issue_number, comment_id,
                           auth, candidate_sha, pinned_main_sha):
    """Return read-only advisory evidence, never a write or retry decision."""
    denied = {
        "decision": "DENY", "write_allowed": False, "retry_allowed": False,
        "merge_allowed": False, "dispatch_allowed": False,
        "production_authorized": False, "ready_for_activation": False,
    }
    if (not callable(api_get) or not isinstance(pinned_main_sha, str) or
            not SHA.fullmatch(pinned_main_sha) or not isinstance(auth, dict)):
        return dict(denied, reason="Missing pinned main or invalid audit input")
    proposal = verify_candidate_readonly(
        api_get, pr_number, issue_number, comment_id, auth, candidate_sha
    )
    if proposal.get("decision") != "REVIEW_ONLY":
        return dict(denied, reason="Candidate or owner evidence denied")
    if (proposal.get("write_allowed") is not False or
            proposal.get("merge_allowed") is not False or
            proposal.get("dispatch_allowed") is not False or
            proposal.get("production_authorized") is not False or
            proposal.get("reservation_before_branch") is not True or
            proposal.get("never_retry_same_issue") is not True):
        return dict(denied, reason="Unsafe or incomplete nonce/branch plan")
    branch_ref = proposal.get("sandbox_ref")
    nonce_ref = proposal.get("nonce_ref")
    approved_head = proposal.get("expected_parent")
    expected_branch = "refs/heads/"
    expected_tag = "refs/tags/phase-c-once-issue-" + str(auth.get("issue_id"))
    if (not isinstance(branch_ref, str) or
            not branch_ref.startswith(expected_branch) or
            not BRANCH.fullmatch(branch_ref[len(expected_branch):]) or
            nonce_ref != expected_tag or
            not isinstance(approved_head, str) or not SHA.fullmatch(approved_head)):
        return dict(denied, reason="Invalid advisory ref identifiers")

    main_path = ROOT + "/git/ref/heads/main"
    branch_path = ROOT + "/git/ref/heads/" + branch_ref[len(expected_branch):]
    nonce_path = ROOT + "/git/ref/tags/phase-c-once-issue-" + str(auth["issue_id"])
    try:
        observations = []
        for _ in range(2):
            main = _commit_ref(api_get(main_path), "refs/heads/main")
            branch = _commit_ref(api_get(branch_path), branch_ref)
            try:
                nonce = _commit_ref(api_get(nonce_path), nonce_ref)
            except MissingNonceRef:
                nonce = None  # Unverified 404, NOT durable absence evidence.
            observations.append((main, branch, nonce))
        if observations[0] != observations[1]:
            return dict(denied, reason="Ref state changed during read-only audit",
                        observations=observations)
        main, branch, nonce = observations[0]
        if main != pinned_main_sha:
            return dict(denied, reason="Pinned main SHA drift", observed_main=main)
        if branch != approved_head:
            return dict(denied, reason="Approved sandbox branch head drift",
                        observed_branch=branch)
        if nonce is not None:
            return dict(denied, reason="Issue-scoped nonce tag already exists",
                        observed_nonce=nonce)

        # Even two matching 404 readbacks are no evidence of write permission
        # or of atomic reservation, because API 404 may conceal a ref.
        return {
            **denied,
            "decision": "READ_ONLY_REVIEW_REQUIRED",
            "reason": "Matching reads only; owner/transport and nonce absence unproven",
            "pinned_main_sha": pinned_main_sha,
            "approved_branch_sha": approved_head,
            "candidate_sha": candidate_sha,
            "sandbox_ref": branch_ref,
            "nonce_ref": nonce_ref,
            "nonce_status": "404_NOT_PROOF_OF_ABSENCE",
            "blockers": [
                "No independently authenticated trusted writer",
                "GitHub branch and tag rules not verified",
                "Tag 404 does not prove reservation availability",
                "No durable nonce reservation performed",
                "No separately scoped write or execution authorization",
            ],
        }
    except (GitHubReadError, ValueError, TypeError, KeyError, AttributeError,
            OSError):
        return dict(denied, reason="Ref evidence missing, invalid or inaccessible")
