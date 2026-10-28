#!/usr/bin/env python3
"""B5c: compose existing bounded GitHub collector and offline B5b reporter.

Caller provides a GitHub Actions:read token and an already captured B4
observation/execution metadata. No dispatch, OVH access or acceptance writes.
"""
from controller_evidence_collector import collect
from controller_b5b_offline_report import render


def collect_and_render(*, release_id, release_sha, release_run_id, expected_paths,
                       authorized_main_sha, observation, execution_run, token,
                       collector=collect):
    if not isinstance(token, str) or not token:
        raise ValueError("Explicit Actions:read token required")
    base = collector(release_run_id, release_sha, expected_paths, token)
    return render({
        "base_report": base,
        "observation": observation,
        "execution_run": execution_run,
        "release_id": release_id,
        "release_sha": release_sha,
        "release_run_id": release_run_id,
        "expected_paths": expected_paths,
        "authorized_main_sha": authorized_main_sha,
    })


def validate_b4_run_identity(run, jobs, *, expected_b4_run_id, expected_main_sha):
    """Offline fail-closed check of separate B4 GitHub run and readback job.

    Caller-fetched metadata is NOT independently authenticated by this check.
    """
    import re
    if (type(expected_b4_run_id) is not int or expected_b4_run_id <= 0 or
            not isinstance(expected_main_sha, str) or
            not re.fullmatch(r"[a-f0-9]{40}", expected_main_sha)):
        raise ValueError("Invalid expected B4 identity")
    if not isinstance(run, dict) or not isinstance(jobs, list):
        return {"status": "INCOMPLETE", "reason": "b4_run_metadata_missing"}
    if (run.get("id") != expected_b4_run_id or
            run.get("head_sha") != expected_main_sha):
        return {"status": "MISMATCH", "reason": "b4_run_identity_mismatch"}
    if (run.get("event") != "workflow_dispatch" or
            run.get("head_branch") != "main" or
            run.get("path") != ".github/workflows/b4-ovh-verification.yml" or
            run.get("status") != "completed" or run.get("conclusion") != "success"):
        return {"status": "INCOMPLETE", "reason": "b4_workflow_not_successful"}
    matches = [j for j in jobs if isinstance(j, dict) and j.get("name") == "readback"]
    if len(matches) != 1:
        return {"status": "INCOMPLETE", "reason": "b4_readback_job_missing_or_duplicate"}
    job = matches[0]
    if (job.get("status") != "completed" or job.get("conclusion") != "success" or
            job.get("head_sha") != expected_main_sha):
        return {"status": "INCOMPLETE", "reason": "b4_readback_job_not_verified"}
    return {"status": "VERIFIED", "reason": "supplied_b4_run_job_consistent",
            "independent_provenance_authenticated": False}


def unify_offline_b5_evidence(*, base_report, observation, b4_run, b4_jobs,
                              release_id, release_sha, release_run_id,
                              expected_paths, authorized_main_sha, b4_run_id,
                              b4_execution_sha):
    """Join B5b and B5d reports; neither caller inputs nor success grant authority."""
    b4 = validate_b4_run_identity(
        b4_run, b4_jobs, expected_b4_run_id=b4_run_id,
        expected_main_sha=b4_execution_sha)
    combined = render({
        "base_report": base_report, "observation": observation,
        "execution_run": b4_run, "release_id": release_id,
        "release_sha": release_sha, "release_run_id": release_run_id,
        "expected_paths": expected_paths,
        "authorized_main_sha": authorized_main_sha,
    })
    # B4 verification was a separate run, not the deployment run.
    # Require both checks positive before reporting offline consistency.
    if b4["status"] == "MISMATCH" or combined["status"] == "MISMATCH":
        status = "MISMATCH"
    elif b4["status"] == "VERIFIED" and combined["status"] == "VERIFIED":
        status = "VERIFIED"
    else:
        status = "INCOMPLETE"
    return {
        "schema": 1, "status": status,
        "reason": ("both_offline_checks_consistent" if status == "VERIFIED"
                   else "one_or_more_offline_checks_not_verified"),
        "b4_run_identity": b4,
        "readback_consistency": combined,
        "evidence_scope": "offline_consistency_only",
        "independent_provenance_authenticated": False,
        "fully_accepted": False, "deployment_authorized": False,
        "dispatch_started": False, "browser_acceptance": "PENDING",
    }
