#!/usr/bin/env python3
"""Phase A: opt-in, read-only GitHub release evidence retrieval and reconciliation.

No workflow dispatch, repository writes, OVH connection or acceptance changes.
Only GitHub REST GET on a fixed repository. A token with Actions:read suffices.
"""
import argparse
import io
import json
import os
import re
import sys
import urllib.request
import zipfile

from controller_readonly_reconciliation import reconcile

BASE = "https://api.github.com/repos/pecharko/Veiling"
SHA = re.compile(r"^[a-f0-9]{40}$")
MAX_ZIP = 8 * 1024 * 1024
MAX_JSON = 1024 * 1024


def get_bytes(url, token, *, limit):
    if not url.startswith(BASE + "/"):
        raise ValueError("Refusing non-repository API URL")
    req = urllib.request.Request(url, headers={
        "Accept": "application/vnd.github+json",
        "Authorization": "Bearer " + token,
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "Veiling-ReadOnly-Evidence/1",
    }, method="GET")
    with urllib.request.urlopen(req, timeout=20) as response:
        data = response.read(limit + 1)
    if len(data) > limit:
        raise ValueError("Response exceeds bounded read limit")
    return data


def get_json(path, token):
    return json.loads(get_bytes(BASE + path, token, limit=MAX_JSON))


def collect(run_id, release_sha, expected_paths, token, *, read_json=get_json,
            read_bytes=get_bytes):
    """Read exact GitHub run/job/artifact metadata; never infer OVH success."""
    if type(run_id) is not int or run_id <= 0 or not SHA.fullmatch(release_sha):
        raise ValueError("Invalid immutable run identity")
    if not expected_paths or len(expected_paths) != len(set(expected_paths)):
        raise ValueError("Invalid exact file allowlist")
    run = read_json("/actions/runs/" + str(run_id), token)
    jobs_page = read_json("/actions/runs/" + str(run_id) + "/jobs?per_page=100", token)
    artifacts_page = read_json("/actions/runs/" + str(run_id) + "/artifacts?per_page=100", token)
    if (jobs_page.get("total_count", 0) > 100 or
            artifacts_page.get("total_count", 0) > 100):
        raise ValueError("Paginated evidence is incomplete")
    jobs = jobs_page.get("jobs")
    artifacts = artifacts_page.get("artifacts")
    evidence_name = "shared-ovh-evidence-" + str(run_id)
    matches = [a for a in artifacts if isinstance(a, dict) and a.get("name") == evidence_name]
    document = None
    if len(matches) == 1 and matches[0].get("expired") is False:
        artifact_id = matches[0].get("id")
        if type(artifact_id) is int and artifact_id > 0:
            raw = read_bytes(BASE + "/actions/artifacts/" + str(artifact_id) + "/zip",
                             token, limit=MAX_ZIP)
            with zipfile.ZipFile(io.BytesIO(raw)) as archive:
                members = archive.infolist()
                if (len(members) != 1 or members[0].filename != "deployment-evidence.json"
                        or members[0].file_size > MAX_JSON or members[0].is_dir()):
                    raise ValueError("Unexpected evidence archive content")
                with archive.open(members[0]) as handle:
                    payload = handle.read(MAX_JSON + 1)
                    if len(payload) > MAX_JSON:
                        raise ValueError("Unbounded evidence document")
                    document = json.loads(payload)
    return reconcile(run, jobs, document, artifacts, run_id=run_id,
                     release_sha=release_sha, expected_paths=expected_paths)


def main():
    parser = argparse.ArgumentParser(description="Read-only GitHub evidence report; no deployment")
    parser.add_argument("--run-id", type=int, required=True)
    parser.add_argument("--release-sha", required=True)
    parser.add_argument("--path", action="append", required=True)
    args = parser.parse_args()
    token = os.environ.get("GITHUB_TOKEN")
    if not token:
        parser.error("GITHUB_TOKEN with Actions:read is required")
    result = collect(args.run_id, args.release_sha, args.path, token)
    print(json.dumps(result, indent=2, sort_keys=True))
    # A successful metadata retrieval is never a production acceptance result.
    return 0


if __name__ == "__main__":
    sys.exit(main())
