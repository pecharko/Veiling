#!/usr/bin/env python3
"""Read-only GitHub API provenance fetch for a *separately invoked* browser check.

This module does not run in Actions, schedule browser tests, access OVH, or
accept production authority. A trusted operator/runner must supply its own
authenticated gh CLI context. A GitHub API transport authenticates the
repository/run/artifact bytes, NOT the independence of the browser runner.
"""
import base64
import json
import re
import subprocess
import tempfile

from release_b_zip_review import evidence_artifact, read_evidence_zip, MAX_ZIP
from release_b_artifact_contract import reconcile
from release_b_public_browser import preflight_browser_run
from release_b_job_log_binding import bind_executed_release, release_job_id, MAX_JOB_LOG

REPO = "pecharko/Veiling"
RELEASE = re.compile(r"^[a-z0-9][a-z0-9-]{2,60}$")
SHA = re.compile(r"^[0-9a-f]{40}$")
MAX_RUN_JSON = 65536
MAX_LIST_JSON = 131072
MAX_MANIFEST_RESPONSE = 100000
MAX_MANIFEST = 65536
# Internal bounded transport route, not a GitHub REST API endpoint.
JOB_LOG_ROUTE = re.compile(r"^repos/pecharko/Veiling/actions/runs/([1-9][0-9]*)/jobs/([1-9][0-9]*)/logs$")


def api_get(path, *, max_bytes):
    """GET-only external gh transport. No retry on errors; do not print tokens."""
    if not isinstance(path, str) or not path.startswith("repos/pecharko/Veiling/"):
        raise ValueError("Non-allowlisted GitHub API route")
    if not isinstance(max_bytes, int) or not 0 < max_bytes <= 262144:
        raise ValueError("Invalid GitHub response bound")
    # Spool the API response to a temporary file instead of collecting an
    # unbounded response in Python memory. Read at most max_bytes + 1 bytes.
    # The REST job-log endpoint responds with a temporary redirect. gh api
    # does not reliably follow its binary download on macOS. gh run view
    # --log is the tested authenticated CLI transport for this one log.
    # The internal path binds *both* immutable run and verified job IDs.
    log_route = JOB_LOG_ROUTE.fullmatch(path)
    if log_route:
        run_id, job_id = log_route.groups()
        command = ["gh", "run", "view", run_id, "--repo", REPO,
                   "--job", job_id, "--log"]
    elif "/logs" in path:
        raise ValueError("Non-allowlisted GitHub log route")
    else:
        command = ["gh", "api", "--method", "GET", path]
    try:
        with tempfile.TemporaryFile() as output:
            subprocess.run(command,
                           stdout=output, stderr=subprocess.DEVNULL,
                           check=True, timeout=30)
            output.seek(0)
            bounded = output.read(max_bytes + 1)
    except (OSError, subprocess.CalledProcessError, subprocess.TimeoutExpired):
        raise ValueError("Authenticated GitHub GET was not successful") from None
    if not 0 < len(bounded) <= max_bytes:
        raise ValueError("Invalid GitHub response size")
    return bounded


def json_response(raw, maximum):
    if not isinstance(raw, bytes) or not 0 < len(raw) <= maximum:
        raise ValueError("Invalid GitHub JSON response size")
    try:
        value = json.loads(raw.decode("utf-8"))
    except (UnicodeError, json.JSONDecodeError) as exc:
        raise ValueError("Malformed GitHub JSON response") from exc
    if not isinstance(value, dict):
        raise ValueError("Expected GitHub JSON object")
    return value


def manifest_content(response):
    if (not isinstance(response, dict) or response.get("type") != "file"
            or response.get("encoding") != "base64"
            or type(response.get("size")) is not int
            or not 0 < response["size"] <= MAX_MANIFEST):
        raise ValueError("Invalid immutable manifest response")
    raw64 = response.get("content")
    if not isinstance(raw64, str) or not 0 < len(raw64) <= MAX_MANIFEST * 2:
        raise ValueError("Invalid base64 manifest")
    try:
        raw = base64.b64decode("".join(raw64.split()), validate=True)
    except (ValueError, base64.binascii.Error) as exc:
        raise ValueError("Invalid manifest encoding") from exc
    if len(raw) != response["size"]:
        raise ValueError("Manifest size mismatch")
    return json_response(raw, MAX_MANIFEST)


def collect_verified_browser_inputs(run_id, fetch=api_get):
    """Fetch one authenticated release proof set. No local files or site calls.

    The injected fetch is ONLY for offline tests; production callers must use
    the actual authenticated GitHub GET transport, not fake JSON files.
    """
    if type(run_id) is not int or run_id <= 0:
        raise ValueError("Invalid GitHub deployment run number")
    base = "repos/" + REPO
    run = json_response(fetch(f"{base}/actions/runs/{run_id}", max_bytes=MAX_RUN_JSON),
                        MAX_RUN_JSON)
    if run.get("id") != run_id or type(run.get("id")) is not int:
        raise ValueError("Wrong GitHub run")
    # Fail closed before querying artifacts from an unexpected run.
    if (run.get("event") != "workflow_dispatch"
            or run.get("head_branch") != "main"
            or run.get("status") != "completed"
            or run.get("conclusion") != "success"
            or not isinstance(run.get("head_sha"), str)
            or not SHA.fullmatch(run["head_sha"])):
        raise ValueError("Untrusted or incomplete deployment run")
    args = json_response(
        fetch(f"{base}/actions/runs/{run_id}/artifacts?per_page=100",
              max_bytes=MAX_LIST_JSON), MAX_LIST_JSON)
    artifacts = args.get("artifacts")
    if (not isinstance(artifacts, list) or not isinstance(args.get("total_count"), int)
            or type(args["total_count"]) is not int
            or args["total_count"] != len(artifacts)
            or len(artifacts) > 100):
        raise ValueError("Incomplete or unbounded GitHub artifacts page")
    artifact_id = evidence_artifact(run, args)
    zip_bytes = fetch(f"{base}/actions/artifacts/{artifact_id}/zip",
                      max_bytes=MAX_ZIP)
    evidence = read_evidence_zip(zip_bytes)
    rid = evidence.get("release_id")
    if not isinstance(rid, str) or not RELEASE.fullmatch(rid):
        raise ValueError("Invalid release identifier")
    # REST run metadata omits workflow_dispatch inputs. Independently
    # correlate the successfully completed release-job environment log.
    jobs = json_response(fetch(
        f"{base}/actions/runs/{run_id}/jobs?per_page=100",
        max_bytes=MAX_LIST_JSON), MAX_LIST_JSON)
    release_job = release_job_id(run, jobs)
    log_bytes = fetch(f"{base}/actions/runs/{run_id}/jobs/{release_job}/logs",
                      max_bytes=MAX_JOB_LOG)
    binding = bind_executed_release(run, rid, jobs, log_bytes)
    sha = run["head_sha"]
    response = json_response(
        fetch(f"{base}/contents/releases/{rid}.json?ref={sha}",
              max_bytes=MAX_MANIFEST_RESPONSE), MAX_MANIFEST_RESPONSE)
    manifest = manifest_content(response)
    result = reconcile(run, evidence, manifest, rid)
    if result["status"] != "UPLOADER_ARTIFACT_CONTENT_CONSISTENT_ONLY":
        raise ValueError("Deployment artifact content inconsistent")
    # Compatibility with the earlier run-input contract; this field is
    # synthesized ONLY from a separately validated GitHub job log.
    # It does NOT assert that original dispatch inputs were retrieved.
    bound_run = {**run, "inputs": {"release_id": binding["release_id"]}}
    identity = preflight_browser_run(bound_run, evidence)
    return {
        "run_id": identity["run_id"],
        "release_id": identity["release_id"],
        "release_sha": identity["release_sha"],
        "github_artifact_id": artifact_id,
        "github_provenance": "GITHUB_API_GET_SOURCE_BOUND",
        "job_log_binding": binding["binding_origin"],
        "release_job_id": binding["job_id"],
        "original_dispatch_inputs_authenticated": False,
        "independent_browser_origin_authenticated": False,
        "independent_ovh_readback": "PENDING",
        "release_administration": "OPEN",
    }
