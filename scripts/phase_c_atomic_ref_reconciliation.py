"""Read-only reconciliation after an uncertain Phase C updateRefs outcome.

An ambiguous network result is NEVER retried. Two bounded rounds of GitHub
ref GETs can provide advisory evidence only; they do not prove write authority,
an atomic snapshot, authenticated owner approval or permission to deploy.
"""
import re

from scripts.phase_c_github_readonly_client import (
    ROOT, GitHubReadError, MissingNonceRef,
)

SHA = re.compile(r"^[a-f0-9]{40}$")
BRANCH = re.compile(r"^test/phase-c-[a-z0-9-]{1,40}$")
REPO_NODE_ID = "R_kgDOVNLxvg"


def _commit_ref(response, exact_ref):
    if (not isinstance(response, dict) or
            response.get("ref") != exact_ref or
            not isinstance(response.get("object"), dict) or
            response["object"].get("type") != "commit"):
        raise ValueError("Ref not a direct commit ref")
    sha = response["object"].get("sha")
    if not isinstance(sha, str) or SHA.fullmatch(sha) is None:
        raise ValueError("Invalid ref commit SHA")
    return sha


def reconcile_atomic_refs_readonly(api_get, issue_id, branch, original_head,
                                   candidate_head):
    """Reconcile two exact refs without issuing a write or another attempt.

    A 404 for the tag cannot establish write privileges or permission; even a
    stable 404 with an unchanged branch remains uncertain, NOT retryable.
    """
    refused = {
        "decision": "MANUAL_REVIEW", "write_allowed": False,
        "retry_allowed": False, "merge_allowed": False,
        "dispatch_allowed": False, "production_authorized": False,
    }
    if (not callable(api_get) or type(issue_id) is not int or issue_id <= 0 or
            not isinstance(branch, str) or not BRANCH.fullmatch(branch) or
            not isinstance(original_head, str) or not SHA.fullmatch(original_head) or
            not isinstance(candidate_head, str) or not SHA.fullmatch(candidate_head) or
            candidate_head == original_head):
        return dict(refused, reason="Invalid immutable reconciliation scope")
    name_tag = "refs/tags/phase-c-once-issue-" + str(issue_id)
    name_head = "refs/heads/" + branch
    tag_path = ROOT + "/git/ref/tags/phase-c-once-issue-" + str(issue_id)
    head_path = ROOT + "/git/ref/heads/" + branch
    try:
        repo = api_get(ROOT)
        if (not isinstance(repo, dict) or
                repo.get("full_name") != "pecharko/Veiling" or
                repo.get("node_id") != REPO_NODE_ID or
                repo.get("default_branch") != "main" or
                repo.get("archived") is not False):
            raise ValueError("Wrong GitHub repository")

        rounds = []
        for _ in range(2):
            try:
                nonce = _commit_ref(api_get(tag_path), name_tag)
            except MissingNonceRef:
                nonce = None  # A reported GitHub 404, NOT an authorization.
            head = _commit_ref(api_get(head_path), name_head)
            rounds.append((nonce, head))
        if rounds[0] != rounds[1]:
            return dict(refused, reason="Refs changed between readbacks",
                        observed=rounds)
        nonce, head = rounds[0]
        if nonce == candidate_head and head == candidate_head:
            return dict(refused, decision="MATCHING_REFS_OBSERVED",
                        reason="Two matching readbacks; advisory only, no retry",
                        observed=rounds)
        if nonce is None and head == original_head:
            return dict(refused, decision="UNCHANGED_REPORTED_NO_RETRY",
                        reason="Nonce returned 404; ambiguity remains, never retry",
                        observed=rounds)
        return dict(refused, reason="Partial, conflicting or unexpected ref state",
                    observed=rounds)
    except (GitHubReadError, ValueError, TypeError, KeyError, AttributeError,
            OSError):
        return dict(refused, reason="Unverifiable GitHub ref readback")
