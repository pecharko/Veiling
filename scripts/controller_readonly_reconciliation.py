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
    # Provenance is categorical: a workflow's self-reported readback is
    # never equivalent to a second independent check against OVH.
    output["provenance"] = {
        "github_actions": {
            "source": "github_api_run_jobs",
            "status": run_result["status"],
            "reason": run_result["reason"],
            "run_id": run_id,
            "release_sha": release_sha,
        },
        "artifact_and_rollback": {
            "source": "github_api_artifact_inventory_and_runner_json",
            "status": artifact_result["status"],
            "reason": artifact_result["reason"],
            "run_id": run_id,
            "release_sha": release_sha,
            "retention_7_days_independently_verified": False,
        },
        "runner_reported_sftp_readback": {
            "source": "same_deployment_runner_not_independent",
            "status": "NOT_INDEPENDENTLY_VERIFIED",
        },
        "external_ovh_readback": {
            "source": "independent_ovh_read_not_performed",
            "status": "NOT_VERIFIED",
        },
        "browser_acceptance": {
            "source": "user_browser_confirmation_not_collected",
            "status": "PENDING",
        },
    }
    return output
