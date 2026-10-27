#!/usr/bin/env python3
"""Pure read-only binding of independently retrieved Actions run/job/step metadata.

Not a production verification, dispatch authorization, or network client.
"""
import re

SHA = re.compile(r"^[0-9a-f]{40}$")
WORKFLOW = ".github/workflows/shared-ovh-release.yml"
REQUIRED_STEPS = (
    "Immutable current main SHA",
    "Offline manifest validation and source hashes",
    "Verify exact per-release final authorization",
    "Pinned SFTP client",
    "Backup and upload exactly manifest files with drift gate and rollback",
    "Preserve machine-readable observed release evidence",
    "Preserve rollback artifact even on failure",
    "Request independent browser acceptance",
)


def inspect_run(run, jobs, *, run_id, release_sha):
    """Never turn successful Actions metadata into verified OVH readback."""
    source = {"run_id": run_id, "release_sha": release_sha}
    def result(status, reason):
        return {"status": status, "reason": reason, "source": source.copy()}
    if type(run_id) is not int or run_id <= 0 or not isinstance(release_sha, str) or not SHA.fullmatch(release_sha):
        raise ValueError("Invalid trusted identity")
    if not isinstance(run, dict):
        return result("INCOMPLETE", "missing_run")
    if run.get("id") != run_id or run.get("head_sha") != release_sha or run.get("path") not in (
            WORKFLOW, "pecharko/Veiling/" + WORKFLOW):
        return result("FAILED", "immutable_run_identity_mismatch")
    if run.get("event") != "workflow_dispatch" or run.get("head_branch") != "main":
        return result("FAILED", "not_a_manually_dispatched_main_release")
    if run.get("status") != "completed":
        return result("PENDING" if run.get("status") in ("queued", "in_progress", "waiting", "pending")
                      else "INCOMPLETE", "run_not_completed")
    if run.get("conclusion") != "success":
        return result("FAILED", "workflow_not_successful")
    if not isinstance(jobs, list) or not jobs:
        return result("INCOMPLETE", "missing_jobs")
    matching = [x for x in jobs if isinstance(x, dict) and x.get("name") == "release"]
    if len(matching) != 1:
        return result("INCOMPLETE", "missing_or_duplicate_release_job")
    job = matching[0]
    if job.get("status") != "completed" or job.get("conclusion") != "success":
        return result("FAILED", "release_job_not_successful")
    steps = job.get("steps")
    if not isinstance(steps, list):
        return result("INCOMPLETE", "missing_steps")
    for name in REQUIRED_STEPS:
        matches = [x for x in steps if isinstance(x, dict) and x.get("name") == name]
        if len(matches) != 1:
            return result("INCOMPLETE", "missing_or_duplicate_required_step")
        if matches[0].get("status") != "completed" or matches[0].get("conclusion") != "success":
            return result("FAILED", "required_step_not_successful")
    return result("INCOMPLETE", "actions_success_not_independent_remote_proof")
