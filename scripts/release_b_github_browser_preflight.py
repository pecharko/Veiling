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

from release_b_zip_review import evidence_artifact, read_evidence_zip, MAX_ZIP
from release_b_artifact_contract import reconcile
from release_b_public_browser import preflight_browser_run

REPO = "pecharko/Veiling"
RELEASE = re.compile(r"^[a-z0-9][a-z0-9-]{2,60}$")
SHA = re.compile(r"^[0-9a-f]{40}$")
MAX_RUN_JSON = 65536
MAX_LIST_JSON = 131072
MAX_MANIFEST_RESPONSE = 100000
MAX_MANIFEST = 65536


def api_get(path, *, max_bytes):
    """GET-only external gh transport. No retry on errors; do not print tokens."""
    if not isinstance(path, str) or not path.startswith("repos/pecharko/Veiling/"):
        raise ValueError("Non-allowlisted GitHub API route")
    if not isinstance(max_bytes, int) or not 0 < max_bytes <= 262144:
        raise ValueError("Invalid GitHub response bound")
    try:
        result = subprocess.run(["gh", "api", "--method", "GET", path],
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                check=True, timeout=30)
    except (OSError, subprocess.CalledProcessError, subprocess.TimeoutExpired) as exc:
        raise ValueError("Authenticated GitHub GET was not successful") from None
    if not 0 < len(result.stdout) <= max_bytes:
        raise ValueError("Invalid GitHub response size")
    return result.stdout


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
    if not isinstance(run.get("inputs"), dict) or run["inputs"].get("release_id") != rid:
        raise ValueError("Original deployment input differs from artifact")
    sha = run["head_sha"]
    response = json_response(
        fetch(f"{base}/contents/releases/{rid}.json?ref={sha}",
              max_bytes=MAX_MANIFEST_RESPONSE), MAX_MANIFEST_RESPONSE)
    manifest = manifest_content(response)
    result = reconcile(run, evidence, manifest, rid)
    if result["status"] != "UPLOADER_ARTIFACT_CONTENT_CONSISTENT_ONLY":
        raise ValueError("Deployment artifact content inconsistent")
    identity = preflight_browser_run(run, evidence)
    return {
        "run_id": identity["run_id"],
        "release_id": identity["release_id"],
        "release_sha": identity["release_sha"],
        "github_artifact_id": artifact_id,
        "github_provenance": "GITHUB_API_GET_SOURCE_BOUND",
        "independent_browser_origin_authenticated": False,
        "independent_ovh_readback": "PENDING",
        "release_administration": "OPEN",
    }
