#!/usr/bin/env python3
"""Bounded read-only repair evidence retrieval, reusing existing gh GET transport.

No credential loading, token minting, HTTP mutation, Git operations, workflow
dispatch, retries, or file writes. A caller-supplied GET is untrusted and never
grants owner/source authentication or execution rights. Not wired to Actions.
"""
import hashlib
import json
import subprocess

from scripts.development_ci_write_gate import (
    assess_repair_write, review_github_readbacks,
)

SCOPE = "pecharko/Veiling:phase-c-repair-readonly"
ROOT = "repos/pecharko/Veiling"
DENIED = {
    "decision": "DENY",
    "reason_code": "READ_ONLY_EVIDENCE_DENIED",
    "get_request_count": 0,
    "readback_structurally_consistent": False,
    "git_ref_updates_executed": 0,
    "can_write": False,
    "can_merge": False,
    "can_dispatch": False,
    "production_authorized": False,
    "write_allowed": False,
    "retry_allowed": False,
    "nonce_reserved": False,
    "owner_identity_verified": False,
    "source_provenance_authenticated": False,
    "scope": SCOPE,
}


def _digest(report):
    encoded = json.dumps(report, sort_keys=True, separators=(",", ":"),
                         ensure_ascii=True).encode("ascii")
    return hashlib.sha256(encoded).hexdigest()


def review_get_session(request, api_get):
    """Read PR, sandbox branch and pinned file twice; advisory only.

    The caller should supply the existing development_ci_feedback_adapter.github_get
    for real read-only use. A fake callable may supply invented responses: matching
    responses are NOT proof of API authenticity, permission or atomicity. No
    owner approval, nonce, CI success or ref update is established here.
    """
    report = dict(DENIED)
    if (type(request) is not dict or not callable(api_get)
            or assess_repair_write(request).get("decision")
               != "ELIGIBLE_FOR_SEPARATE_WRITE_APPROVAL"):
        report["report_sha256"] = _digest(report)
        return report

    pr = ROOT + "/pulls/" + str(request["pr"])
    branch = ROOT + "/git/ref/heads/" + request["branch"]
    file = (ROOT + "/contents/" + request["path"] + "?ref="
            + request["expected_head_sha"])
    observed = 0
    reviewed = []
    try:
        for _ in range(2):
            observed += 1
            pr_data = api_get(pr)
            observed += 1
            branch_data = api_get(branch)
            observed += 1
            file_data = api_get(file)
            verdict = review_github_readbacks(
                request, pr_data, branch_data, file_data,
            )
            if (verdict.get("decision") != "PROPOSED_ONLY"
                    or verdict.get("readback_structurally_consistent") is not True
                    or any(verdict.get(field) is not False for field in (
                        "can_write", "can_merge", "can_dispatch",
                        "production_authorized", "owner_identity_verified",
                        "nonce_reserved", "source_provenance_authenticated"))):
                raise ValueError("Readback review denied")
            reviewed.append(verdict)
        if reviewed[0] != reviewed[1]:
            raise ValueError("Readbacks drifted")
        trusted_fields = (
            "review_sha256", "original_blob_sha", "candidate_blob_sha",
            "candidate_sha256", "head_sha", "pr", "path",
        )
        report.update({
            "decision": "READ_ONLY_REVIEW",
            "reason_code": "TWO_CONSISTENT_GET_PASSES_NOT_WRITE_AUTHORIZATION",
            "readback_structurally_consistent": True,
            **{key: reviewed[0][key] for key in trusted_fields},
        })
    except (OSError, TypeError, ValueError, KeyError,
            subprocess.SubprocessError):
        pass
    report["get_request_count"] = observed
    report["report_sha256"] = _digest(report)
    return report


def review_using_existing_get(request):
    """Opt-in caller path; uses existing fixed-repository gh api GET wrapper."""
    from scripts.development_ci_feedback_adapter import github_get
    return review_get_session(request, github_get)


if __name__ == "__main__":
    raise SystemExit("Import-only read-only review; no execution or writer entrypoint")
