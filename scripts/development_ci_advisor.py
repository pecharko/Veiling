#!/usr/bin/env python3
"""Fail-closed, read-only advice for development CI failures.

This never writes code, comments, merges, dispatches or authorizes a release.
"""
import json
import re
import sys

SAFE_PATH = re.compile(r"^(tests/[a-zA-Z0-9_/-]+_test\.php|catalogus-public/assets/[a-zA-Z0-9_/-]+\.css)$")
PROTECTED_PREFIX = (".github/", "private/", "database/", "releases/", "release-requests/", "scripts/")


def assess(payload):
    if not isinstance(payload, dict) or set(payload) != {"head_sha", "jobs", "changed_paths"}:
        raise ValueError("Invalid input fields")
    sha, jobs, paths = payload["head_sha"], payload["jobs"], payload["changed_paths"]
    if not isinstance(sha, str) or not re.fullmatch("[a-f0-9]{40}", sha):
        raise ValueError("Invalid immutable SHA")
    if not isinstance(jobs, list) or not jobs or len(jobs) > 100:
        raise ValueError("Missing or excessive jobs")
    if not isinstance(paths, list) or not paths or len(paths) > 100 or len(set(paths)) != len(paths):
        raise ValueError("Missing or invalid paths")
    if any(not isinstance(p, str) or p.startswith(PROTECTED_PREFIX) or not SAFE_PATH.fullmatch(p)
           or ".." in p.split("/") for p in paths):
        return {"status": "HUMAN_REVIEW", "reason": "Protected or unsupported path", "head_sha": sha,
                "automatic_code_changes": False, "deployment_authorized": False}
    failures = []
    for job in jobs:
        if not isinstance(job, dict) or set(job) != {"name", "conclusion"}:
            raise ValueError("Invalid job")
        if job["conclusion"] not in ("success", "failure", "cancelled", "timed_out", "skipped"):
            raise ValueError("Unknown job outcome")
        if job["conclusion"] in ("failure", "cancelled", "timed_out"):
            failures.append(job["name"])
    if not failures:
        status, reason = "CI_GREEN_ADVISORY", "All reported jobs successful or skipped"
    elif len(failures) > 2:
        status, reason = "HUMAN_REVIEW", "More than two failed jobs"
    else:
        status, reason = "FIX_CANDIDATE", "Review job logs and propose a separate narrow fix"
    return {"status": status, "reason": reason, "head_sha": sha,
            "failed_jobs": failures, "automatic_code_changes": False, "deployment_authorized": False}


def main():
    payload = json.load(sys.stdin)
    print(json.dumps(assess(payload), sort_keys=True))


if __name__ == "__main__":
    main()
