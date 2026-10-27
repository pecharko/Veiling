#!/usr/bin/env python3
"""Pure, fail-closed release evidence report. No IO, dispatch or GitHub writes.

Caller supplies independently fetched immutable GitHub REST snapshots.
VERIFIED describes observed workflow execution only, never browser acceptance.
"""
import json
import re

SHA = re.compile(r"^[a-f0-9]{40}$")
STATES = {"PENDING", "VERIFIED", "FAILED", "INCOMPLETE", "NOT_APPLICABLE"}
EXPECTED = ("preflight", "authorization", "dispatch", "deployment",
            "readback", "rollback", "browser_acceptance")


def assess_run(run, jobs, *, expected_sha, expected_workflow, required_job):
    """Require exact identity and one completed, successful required job."""
    ref = {"run_id": None, "sha": expected_sha, "workflow": expected_workflow,
           "job": required_job}
    if (not isinstance(expected_sha, str) or not SHA.fullmatch(expected_sha)
            or not isinstance(expected_workflow, str)
            or not expected_workflow.startswith(".github/workflows/")
            or not expected_workflow.endswith(".yml")
            or not isinstance(required_job, str) or not required_job):
        raise ValueError("Invalid expected immutable evidence identity")
    if not isinstance(run, dict):
        return {"status": "INCOMPLETE", "reason": "missing_run", "source": ref}
    rid = run.get("id")
    if type(rid) is not int or rid <= 0:
        return {"status": "INCOMPLETE", "reason": "invalid_run_id", "source": ref}
    ref["run_id"] = rid
    if (run.get("head_sha") != expected_sha
            or run.get("path") != expected_workflow):
        return {"status": "FAILED", "reason": "identity_mismatch", "source": ref}
    if run.get("status") in ("queued", "in_progress", "waiting", "pending"):
        return {"status": "PENDING", "reason": "run_in_progress", "source": ref}
    if run.get("status") != "completed":
        return {"status": "INCOMPLETE", "reason": "unknown_run_status", "source": ref}
    if run.get("conclusion") != "success":
        return {"status": "FAILED", "reason": "run_not_successful", "source": ref}
    if not isinstance(jobs, list) or not jobs:
        return {"status": "INCOMPLETE", "reason": "missing_jobs", "source": ref}
    matched = [job for job in jobs
               if isinstance(job, dict) and job.get("name") == required_job]
    if len(matched) != 1:
        return {"status": "INCOMPLETE", "reason": "missing_or_duplicate_job", "source": ref}
    job = matched[0]
    if job.get("head_sha") not in (None, expected_sha):
        return {"status": "FAILED", "reason": "job_sha_mismatch", "source": ref}
    if job.get("status") != "completed" or job.get("conclusion") != "success":
        return {"status": "FAILED", "reason": "job_not_successful", "source": ref}
    return {"status": "VERIFIED", "reason": "run_and_job_success", "source": ref}


def report(stages):
    """Never infer verification from an absent stage or another stage."""
    if not isinstance(stages, dict) or set(stages) - set(EXPECTED):
        raise ValueError("Invalid evidence stage inventory")
    items = {}
    for name in EXPECTED:
        entry = stages.get(name)
        if entry is None:
            entry = {"status": "INCOMPLETE", "reason": "not_provided", "source": None}
        if (not isinstance(entry, dict)
                or set(entry) != {"status", "reason", "source"}
                or entry["status"] not in STATES
                or not isinstance(entry["reason"], str) or not entry["reason"]):
            raise ValueError("Untrusted evidence stage")
        if name in ("deployment", "readback", "rollback", "browser_acceptance") and entry["status"] == "NOT_APPLICABLE":
            raise ValueError("Production evidence cannot be declared NVT")
        items[name] = entry.copy()
    # A completed job is not evidence that readback, rollback or acceptance exist.
    return {"schema": 1, "read_only": True, "dispatch_started": False,
            "stages": items, "fully_accepted": all(
                items[k]["status"] == "VERIFIED"
                for k in ("deployment", "readback", "rollback", "browser_acceptance"))}


def as_json(stages):
    return json.dumps(report(stages), sort_keys=True, separators=(",", ":"))


def as_text(stages):
    result = report(stages)
    return "\n".join(
        name + ": " + result["stages"][name]["status"] +
        " (" + result["stages"][name]["reason"] + ")"
        for name in EXPECTED
    )
