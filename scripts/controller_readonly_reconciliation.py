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


def reconcile_independent_b4(base_report, observation, *, release_id, release_sha,
                             run_id, expected_files, execution_run=None,
                             authorized_main_sha=None):
    """Offline, non-authorizing join of existing release and B4 observations.

    The caller supplies independently retrieved GitHub metadata. This function
    cannot authenticate that provenance and never upgrades browser acceptance.
    """
    from re import fullmatch
    if (not isinstance(base_report, dict) or
            not isinstance(release_id, str) or not release_id or
            not isinstance(release_sha, str) or not fullmatch(r"[a-f0-9]{40}", release_sha) or
            type(run_id) is not int or run_id <= 0 or
            not isinstance(expected_files, list) or not expected_files or
            len(set(expected_files)) != len(expected_files) or
            any(not isinstance(p, str) or not p for p in expected_files)):
        raise ValueError("Invalid trusted B5a identity or exact file list")
    outcome = {"status": "INCOMPLETE", "reason": "independent_observation_missing",
               "release_id": release_id, "release_sha": release_sha,
               "release_run_id": run_id, "expected_paths": list(expected_files),
               "browser_acceptance": "PENDING", "fully_accepted": False,
               "deployment_authorized": False, "dispatch_started": False,
               "independent_provenance_authenticated": False}
    deployment = base_report.get("provenance", {}).get("github_actions", {})
    if (not isinstance(deployment, dict) or
            deployment.get("run_id") != run_id or
            deployment.get("release_sha") != release_sha or
            deployment.get("source") != "github_api_run_jobs" or
            deployment.get("reason") != "actions_success_not_independent_remote_proof"):
        outcome["reason"] = "release_evidence_not_bound"
        return outcome
    if not isinstance(observation, dict) or not isinstance(execution_run, dict):
        return outcome
    # The B4 Python verifier emits the observation; the GitHub metadata is
    # a separate input, not an authorization to trigger any further work.
    if (observation.get("release_id") != release_id or
            observation.get("release_sha") != release_sha or
            observation.get("run_id") != run_id):
        outcome.update(status="MISMATCH", reason="release_identity_mismatch")
        return outcome
    if (execution_run.get("event") != "workflow_dispatch" or
            execution_run.get("conclusion") != "success" or
            execution_run.get("status") != "completed" or
            not isinstance(execution_run.get("head_sha"), str) or
            not fullmatch(r"[a-f0-9]{40}", execution_run["head_sha"])):
        outcome["reason"] = "execution_metadata_not_successful"
        return outcome
    if authorized_main_sha is not None:
        if not isinstance(authorized_main_sha, str) or not fullmatch(r"[a-f0-9]{40}", authorized_main_sha):
            raise ValueError("Invalid authorized main SHA")
        if execution_run["head_sha"] != authorized_main_sha:
            outcome.update(status="MISMATCH", reason="execution_commit_outside_authorization",
                           observed_main_sha=execution_run["head_sha"])
            return outcome
    rows = observation.get("files")
    if (observation.get("source") != "separate_read_only_ovh_sftp_session" or
            observation.get("writes_performed") is not False or
            observation.get("checkout_sha_verified") is not True or
            observation.get("schema") != 1 or
            not isinstance(rows, list) or
            len(rows) != len(expected_files)):
        outcome["reason"] = "observation_contract_incomplete"
        return outcome
    if [r.get("path") if isinstance(r, dict) else None for r in rows] != expected_files:
        outcome.update(status="MISMATCH", reason="file_allowlist_mismatch")
        return outcome
    for row in rows:
        expected = row.get("expected_sha256")
        observed = row.get("observed_sha256")
        if not isinstance(expected, str) or not fullmatch(r"[a-f0-9]{64}", expected):
            outcome["reason"] = "invalid_expected_digest"
            return outcome
        if observed != expected or row.get("match") is not True:
            outcome.update(status="MISMATCH", reason="readback_digest_mismatch")
            return outcome
    if observation.get("status") != "OBSERVED_MATCH":
        outcome.update(status="MISMATCH", reason="observation_status_mismatch")
        return outcome
    # Read-only evidence consistency is NOT attested provenance, browser
    # acceptance, deployment authorization, or a decision to dispatch.
    outcome.update(status="VERIFIED", reason="consistent_supplied_b4_evidence",
                   observed_main_sha=execution_run["head_sha"],
                   evidence_scope="offline_consistency_only")
    return outcome
