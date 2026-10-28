#!/usr/bin/env python3
"""Fail-closed, offline consumption of an existing B5 report.

This module has no GitHub, OVH or deployment IO. It never grants authority.
"""
import re

SHA = re.compile(r"^[0-9a-f]{40}$")
REPORT_STATUSES = frozenset(("VERIFIED", "MISMATCH", "INCOMPLETE"))


def summarize(report, *, run_id, head_sha, artifact_name):
    """Bind a caller-supplied report to an explicit observed artifact identity.

    The caller must independently obtain the run, SHA and artifact metadata.
    This checks consistency of supplied data, NOT its provenance.
    """
    if (type(run_id) is not int or run_id <= 0 or
            not isinstance(head_sha, str) or not SHA.fullmatch(head_sha) or
            not isinstance(artifact_name, str) or
            artifact_name != f"b5b-offline-consistency-{run_id}"):
        raise ValueError("Invalid explicit report identity")
    result = {
        "run_id": run_id, "head_sha": head_sha,
        "artifact_name": artifact_name,
        "status": "INCOMPLETE", "reason": "report_missing_or_invalid",
        "evidence_scope": "offline_consistency_only",
        "independent_provenance_authenticated": False,
        "deployment_authorized": False,
        "fully_accepted": False,
        "browser_acceptance": "PENDING",
    }
    if not isinstance(report, dict):
        return result
    status = report.get("status")
    b4 = report.get("b4_run_identity")
    rb = report.get("readback_consistency")
    if (status not in REPORT_STATUSES or
            not isinstance(b4, dict) or not isinstance(rb, dict) or
            b4.get("status") not in REPORT_STATUSES or
            rb.get("status") not in REPORT_STATUSES or
            report.get("independent_provenance_authenticated") is not False or
            report.get("fully_accepted") is not False or
            report.get("deployment_authorized") is not False or
            report.get("dispatch_started") is not False or
            report.get("browser_acceptance") != "PENDING"):
        return result
    # Never upgrade contradictory sub-results even when the top-level claims VERIFIED.
    if "MISMATCH" in (status, b4["status"], rb["status"]):
        result.update(status="MISMATCH", reason=rb.get("reason", "reported_mismatch"))
    elif "INCOMPLETE" in (status, b4["status"], rb["status"]):
        result.update(status="INCOMPLETE", reason="evidence_incomplete")
    else:
        result.update(status="VERIFIED", reason="supplied_report_consistent_only")
    # No authorization or production acceptance on any code path.
    return result
