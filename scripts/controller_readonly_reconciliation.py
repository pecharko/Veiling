#!/usr/bin/env python3
"""Offline aggregation of previously reviewed read-only release checks.

Consumes caller-fetched GitHub snapshots; never dispatches or authorizes releases.
"""
from controller_run_evidence_binding import inspect_run
from controller_artifact_consistency import inspect_release_evidence
from controller_readonly_report import report


def reconcile(run, jobs, document, artifacts, *, run_id, release_sha, expected_paths):
    """Preserve separate provenance, fail closed, never infer acceptance."""
    run_result = inspect_run(run, jobs, run_id=run_id, release_sha=release_sha)
    artifact_result = inspect_release_evidence(
        document, artifacts, run_id=run_id, release_sha=release_sha,
        expected_paths=expected_paths
    )
    # These status values represent only checks of independently provided
    # metadata. No client-provided success is upgraded into OVH verification.
    stages = {
        "deployment": run_result,
        "readback": artifact_result,
        "rollback": artifact_result,
    }
    output = report(stages)
    output["review"] = {
        "run_metadata": run_result,
        "artifact_metadata": artifact_result,
        "independent_remote_readback": "NOT_VERIFIED",
        "independent_browser_acceptance": "PENDING",
    }
    return output
