#!/usr/bin/env python3
"""Fail-closed correlation of a completed deployment job's authenticated log.

GitHub's run REST representation omits workflow_dispatch inputs. The release
job log instead records the RELEASE_ID and DEPLOY_CONFIRMATION environment
passed to the successful protected release job. These are evidence of the
*executed job environment*, not independently authenticated owner consent
or a reconstruction of the original dispatch API input.

NEVER print or persist raw logs: they may contain credentials or personal data.
"""
import re

RELEASE = re.compile(r"^[a-z0-9][a-z0-9-]{2,60}$")
SHA = re.compile(r"^[0-9a-f]{40}$")
MAX_JOBS = 100
MAX_JOB_LOG = 262144
# gh run view --log prefixes lines with job and step labels (tab-separated).
# Accept only those bounded, non-control label fields before the timestamp.
LOG_LINE = re.compile(
    r"^(?:[^\t\r\n]{1,120}\t){0,2}"
    r"\d{4}-\d\d-\d\dT[^\s]+Z\s+"
    r"(RELEASE_ID|DEPLOY_CONFIRMATION):\s+(\S+)\s*$"
)

def release_job_id(run, jobs):
    if not isinstance(jobs, dict) or type(jobs.get("total_count")) is not int:
        raise ValueError("Invalid jobs response")
    items = jobs.get("jobs")
    if (not isinstance(items, list) or len(items) > MAX_JOBS
            or jobs["total_count"] != len(items)):
        raise ValueError("Incomplete deployment jobs")
    matches = [job for job in items if isinstance(job, dict) and job.get("name") == "release"]
    if len(matches) != 1:
        raise ValueError("Missing or ambiguous release job")
    job = matches[0]
    if (type(job.get("id")) is not int or job["id"] <= 0
            or type(job.get("run_id")) is not int or job["run_id"] != run.get("id")
            or job.get("status") != "completed" or job.get("conclusion") != "success"):
        raise ValueError("Unverified release job")
    steps = job.get("steps")
    expected = ("Offline manifest validation and source hashes",
                "Verify exact per-release final authorization",
                "Backup and upload exactly manifest files with drift gate and rollback")
    if not isinstance(steps, list) or any(
        len([step for step in steps if isinstance(step, dict)
             and step.get("name") == name and step.get("conclusion") == "success"]) != 1
        for name in expected
    ):
        raise ValueError("Missing successful release authorization or deployment gate")
    return job["id"]


def bind_executed_release(run, evidence_release_id, jobs, log_bytes):
    """Return a job-observed release binding; do NOT infer original API inputs."""
    if (not isinstance(evidence_release_id, str)
            or not RELEASE.fullmatch(evidence_release_id)
            or not isinstance(run, dict)
            or not isinstance(run.get("head_sha"), str)
            or not SHA.fullmatch(run["head_sha"])):
        raise ValueError("Invalid release identity")
    job_id = release_job_id(run, jobs)
    if not isinstance(log_bytes, bytes) or not 0 < len(log_bytes) <= MAX_JOB_LOG:
        raise ValueError("Missing or oversized authenticated job log")
    try:
        lines = log_bytes.decode("utf-8").splitlines()
    except UnicodeError:
        raise ValueError("Malformed release log") from None
    values = {"RELEASE_ID": set(), "DEPLOY_CONFIRMATION": set()}
    for line in lines:
        match = LOG_LINE.fullmatch(line)
        if match:
            values[match.group(1)].add(match.group(2))
    expected_confirmation = f"DEPLOY-{evidence_release_id}-{run['head_sha']}"
    if (values["RELEASE_ID"] != {evidence_release_id}
            or values["DEPLOY_CONFIRMATION"] != {expected_confirmation}):
        raise ValueError("Executed job environment does not match verified release")
    inputs = run.get("inputs")
    if inputs is not None and (
        not isinstance(inputs, dict) or inputs.get("release_id") != evidence_release_id
    ):
        raise ValueError("Run metadata input conflicts with successful job environment")
    return {"run_id": run["id"], "release_id": evidence_release_id,
            "release_sha": run["head_sha"], "job_id": job_id,
            "binding_origin": "AUTHENTICATED_SUCCESSFUL_JOB_ENV_LOG",
            "original_dispatch_inputs_authenticated": False}
