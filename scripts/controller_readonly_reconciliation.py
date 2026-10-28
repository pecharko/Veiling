#!/usr/bin/env python3
"""Offline aggregation of previously reviewed read-only release checks.

Consumes caller-fetched GitHub snapshots; never dispatches or authorizes releases.
"""
from controller_run_evidence_binding import inspect_run
from controller_artifact_consistency import inspect_release_evidence
from controller_readonly_report import report
from datetime import datetime, timedelta, timezone


def artifact_retention(artifacts, run_id, *, now=None):
    """Observe artifact expiry from GitHub metadata; do not claim storage guarantee."""
    now = now or datetime.now(timezone.utc)
    names = ("shared-ovh-evidence-" + str(run_id),
             "shared-ovh-rollback-" + str(run_id))
    observed = []
    for name in names:
        matches = [a for a in artifacts if isinstance(a, dict) and a.get("name") == name] if isinstance(artifacts, list) else []
        if len(matches) != 1:
            return {"status": "INCOMPLETE", "reason": "artifact_missing_or_duplicate", "artifacts": observed,
                    "retention_7_days_independently_verified": False}
        item = matches[0]
        if (type(item.get("id")) is not int or item["id"] <= 0 or
                item.get("expired") is not False or
                not isinstance(item.get("workflow_run"), dict) or
                item["workflow_run"].get("id") != run_id):
            return {"status": "INCOMPLETE", "reason": "artifact_expired_or_unbound", "artifacts": observed,
                    "retention_7_days_independently_verified": False}
        try:
            created = datetime.fromisoformat(item["created_at"].replace("Z", "+00:00"))
            expires = datetime.fromisoformat(item["expires_at"].replace("Z", "+00:00"))
            if (created.tzinfo is None or expires.tzinfo is None or
                    expires <= now or expires < created + timedelta(days=7)):
                raise ValueError("invalid expiry")
        except (KeyError, AttributeError, TypeError, ValueError):
            return {"status": "INCOMPLETE", "reason": "artifact_retention_unproven", "artifacts": observed,
                    "retention_7_days_independently_verified": False}
        observed.append({"name": name, "id": item["id"],
                         "created_at": created.isoformat(), "expires_at": expires.isoformat(),
                         "source": "github_actions_artifact_api"})
    return {"status": "OBSERVED", "reason": "github_reports_retention_not_independent_storage_proof",
            "artifacts": observed, "retention_7_days_independently_verified": False}


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
    retention = artifact_retention(artifacts, run_id)
    output["artifact_retention"] = retention
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
            "retention_status": retention["status"],
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
