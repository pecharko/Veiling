#!/usr/bin/env python3
"""Phase C3: read-only GitHub Actions CI feedback, reusing the existing advisor.

This is a reporting sidecar, NOT a repair/writer, merge or release controller.
The CLI uses only bounded gh api -X GET requests with the workflow's existing
actions:read / contents:read token. It never checks out or executes PR code.
"""
import json
import os
import re
import subprocess
import sys
from pathlib import Path

if __package__:
    from .development_ci_advisor import assess
else:
    from development_ci_advisor import assess

REPOSITORY = "pecharko/Veiling"
SHA = re.compile(r"^[0-9a-f]{40}$")
MAX_REPLY_BYTES = 2 * 1024 * 1024
MAX_ITEMS = 100
DENIED = {
    "status": "HUMAN_REVIEW",
    "reason": "Incomplete or conflicting read-only GitHub evidence",
    "automatic_code_changes": False,
    "deployment_authorized": False,
    "write_allowed": False,
    "dispatch_allowed": False,
    "release_gate_changed": False,
}


def _sha(value):
    return isinstance(value, str) and SHA.fullmatch(value) is not None


def _denied(reason=DENIED["reason"]):
    return dict(DENIED, reason=reason)


def _get(api_get, path, expected_type):
    value = api_get(path)
    if type(value) is not expected_type:
        raise ValueError("Unexpected GitHub response type")
    return value


def evaluate(event, api_get):
    """Review exactly one completed PR workflow, without mutation authority.

    The api_get callable supplies already authenticated GitHub GET JSON; its
    provenance is the responsibility of the CLI's fixed-origin gh transport.
    Returned reports are advisory, even with successful evidence.
    """
    if not isinstance(event, dict) or not callable(api_get):
        return _denied()
    run = event.get("workflow_run")
    if not isinstance(run, dict):
        return _denied()
    rid, sha = run.get("id"), run.get("head_sha")
    repository = event.get("repository")
    if (event.get("action") != "completed"
            or not isinstance(repository, dict)
            or repository.get("full_name") != REPOSITORY
            or type(rid) is not int or rid <= 0
            or not _sha(sha)
            or run.get("event") != "pull_request"
            or run.get("status") != "completed"
            or run.get("conclusion") not in (
                "success", "failure", "timed_out", "cancelled")):
        return _denied("Not an eligible completed PR workflow")

    root = "repos/" + REPOSITORY
    try:
        actual = _get(api_get, root + "/actions/runs/" + str(rid), dict)
        if (actual.get("id") != rid or actual.get("head_sha") != sha
                or actual.get("event") != "pull_request"
                or actual.get("status") != "completed"
                or actual.get("conclusion") != run["conclusion"]
                or not isinstance(actual.get("repository"), dict)
                or actual["repository"].get("full_name") != REPOSITORY
                or not isinstance(actual.get("head_repository"), dict)
                or actual["head_repository"].get("full_name") != REPOSITORY):
            raise ValueError("Run identity or provenance drift")

        pulls = _get(api_get, root + "/commits/" + sha + "/pulls?per_page=100", list)
        if len(pulls) != 1:
            raise ValueError("Ambiguous or missing pull request binding")
        pr = pulls[0]
        if (not isinstance(pr, dict) or type(pr.get("number")) is not int
                or pr["number"] <= 0 or pr.get("state") != "open"
                or not isinstance(pr.get("head"), dict)
                or not isinstance(pr.get("base"), dict)
                or pr["head"].get("sha") != sha
                or not isinstance(pr["head"].get("repo"), dict)
                or not isinstance(pr["base"].get("repo"), dict)
                or pr["head"]["repo"].get("full_name") != REPOSITORY
                or pr["base"]["repo"].get("full_name") != REPOSITORY
                or not _sha(pr["base"].get("sha"))):
            raise ValueError("PR head, repository or base not trusted")
        base = pr["base"]["sha"]
        if base == sha:
            raise ValueError("PR has no changes")

        jobs = _get(api_get, root + "/actions/runs/" + str(rid)
                    + "/jobs?per_page=100", dict)
        entries = jobs.get("jobs")
        if (type(jobs.get("total_count")) is not int
                or not isinstance(entries, list)
                or not 1 <= len(entries) <= MAX_ITEMS
                or len(entries) != jobs["total_count"]):
            raise ValueError("Missing, excessive or paginated CI jobs")
        safe_jobs = []
        for job in entries:
            if (not isinstance(job, dict)
                    or not isinstance(job.get("name"), str)
                    or not 1 <= len(job["name"]) <= 100
                    or job.get("status") != "completed"
                    or job.get("conclusion") not in (
                        "success", "failure", "cancelled", "timed_out", "skipped")):
                raise ValueError("Unfinished or invalid CI job")
            safe_jobs.append({"name": job["name"], "conclusion": job["conclusion"]})
        # A failed workflow may have zero successful jobs. Rejecting it
        # obscures the most actionable failures; the completed and bounded
        # job list itself is the trustworthy advisory evidence.
        failed = any(j["conclusion"] in ("failure", "cancelled", "timed_out")
                     for j in safe_jobs)
        if run["conclusion"] == "success":
            if failed or not any(j["conclusion"] == "success" for j in safe_jobs):
                raise ValueError("Workflow success contradicts job conclusions")
        elif not failed:
            raise ValueError("Workflow failure lacks any failed or interrupted job")

        compare = _get(api_get, root + "/compare/" + base + "..." + sha, dict)
        files = compare.get("files")
        if (compare.get("status") != "ahead"
                or type(compare.get("behind_by")) is not int
                or compare["behind_by"] != 0
                or type(compare.get("ahead_by")) is not int
                or compare["ahead_by"] < 1
                or not isinstance(files, list)
                or not 1 <= len(files) <= MAX_ITEMS
                or type(compare.get("total_commits")) is not int
                or not 1 <= compare["total_commits"] <= 250):
            raise ValueError("Incomplete or divergent commit comparison")
        if any(not isinstance(f, dict)
               or f.get("status") not in ("modified", "added")
               or not isinstance(f.get("filename"), str)
               for f in files):
            raise ValueError("Unsupported changed file operation")

        advice = assess({
            "head_sha": sha,
            "jobs": safe_jobs,
            "changed_paths": [f["filename"] for f in files],
        })
        if advice["status"] == "CI_GREEN_ADVISORY" and run["conclusion"] != "success":
            raise ValueError("Workflow conclusion is not success")
        return {
            **advice,
            "pr": pr["number"],
            "run_id": rid,
            "evidence_scope": "single_workflow_read_only",
            "overall_pr_ci_verified": False,
            "write_allowed": False,
            "dispatch_allowed": False,
            "release_gate_changed": False,
        }
    except (KeyError, TypeError, ValueError, OSError):
        return _denied()


def github_get(path):
    """Only GET requests to the fixed repository via the existing gh CLI."""
    if not isinstance(path, str) or not path.startswith("repos/" + REPOSITORY + "/"):
        raise ValueError("API path outside repository")
    if not re.fullmatch(r"[A-Za-z0-9_./?=&-]+", path):
        raise ValueError("Unsafe API path")
    result = subprocess.run(
        ["gh", "api", "--method", "GET", path],
        capture_output=True, check=True, timeout=15,
    )
    if len(result.stdout) > MAX_REPLY_BYTES:
        raise ValueError("Oversized GitHub response")
    return json.loads(result.stdout)


def format_summary(output):
    """Present bounded job names without letting remote text inject Markdown."""
    lines = [
        "### Phase C3 development CI feedback (read-only)",
        "- Decision: " + output["status"],
        "- Reason: " + output["reason"],
    ]
    if output.get("run_id"):
        lines.append("- Workflow run: " + str(output["run_id"]))
    if output.get("pr"):
        lines.append("- PR: " + str(output["pr"]))
    if output.get("head_sha"):
        lines.append("- Commit: " + output["head_sha"])
    failed = output.get("failed_jobs")
    if isinstance(failed, list) and failed:
        lines.append("- Failed jobs in this workflow:")
        for name in failed[:MAX_ITEMS]:
            clean = re.sub(r"[^A-Za-z0-9 ._()-]", "_", str(name)[:100]).strip()
            lines.append("  - " + (clean or "(unavailable)"))
    elif output["status"] == "CI_GREEN_ADVISORY":
        lines.append("- Failed jobs: none reported in this workflow")
    else:
        lines.append("- Failed jobs: not independently established; inspect run logs")
    lines += [
        "- Scope: this workflow only; other PR checks require independent review",
        "- No automatic code changes, merge, retry, workflow dispatch or deployment.",
    ]
    return "\n".join(lines)


def main():
    if os.environ.get("GH_REPOSITORY") != REPOSITORY:
        output = _denied()
    else:
        try:
            path = Path(os.environ["GITHUB_EVENT_PATH"])
            if path.stat().st_size > MAX_REPLY_BYTES:
                raise ValueError("Oversized event")
            event = json.loads(path.read_text(encoding="utf-8"))
            output = evaluate(event, github_get)
        except (KeyError, OSError, ValueError, subprocess.SubprocessError):
            output = _denied()
    # GitHub step summary is only an observation; it can never dispatch.
    print(format_summary(output))
    return 0


if __name__ == "__main__":
    sys.exit(main())
