#!/usr/bin/env python3
"""Read-only GitHub API discovery of evidence from the latest shared OVH deployment.

No workflow_dispatch, no GitHub mutations, no OVH access. Requires GITHUB_TOKEN.
"""
import base64
import io
import json
import os
import sys
import urllib.request
import zipfile

from ovh_release_evidence_review import verify

API = "https://api.github.com/repos/pecharko/Veiling"
WORKFLOW = "shared-ovh-release.yml"
MAX_ZIP = 2 * 1024 * 1024
MAX_JSON = 256 * 1024


def get(url, token):
    req = urllib.request.Request(url, headers={
        "Authorization": "Bearer " + token,
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
    })
    with urllib.request.urlopen(req, timeout=20) as response:
        data = response.read(MAX_ZIP + 1)
    if len(data) > MAX_ZIP:
        raise ValueError("Oversized GitHub API response")
    return data


def json_get(url, token):
    return json.loads(get(url, token))


def read_evidence_zip(blob):
    with zipfile.ZipFile(io.BytesIO(blob)) as archive:
        members = archive.infolist()
        if len(members) != 1 or members[0].filename != "deployment-evidence.json":
            raise ValueError("Unexpected evidence archive members")
        member = members[0]
        if member.file_size > MAX_JSON or member.compress_size > MAX_ZIP:
            raise ValueError("Oversized evidence file")
        return json.loads(archive.read(member))


def inspect_run(run, token):
    run_id = run["id"]
    artifacts = json_get(API + "/actions/runs/" + str(run_id) + "/artifacts?per_page=100", token)["artifacts"]
    match = [a for a in artifacts if a.get("name") == "shared-ovh-evidence-" + str(run_id)
             and a.get("expired") is False]
    if len(match) != 1:
        raise ValueError("Evidence artifact not available")
    evidence = read_evidence_zip(get(API + "/actions/artifacts/" + str(match[0]["id"]) + "/zip", token))
    release_id = evidence.get("release_id", "")
    # Bound path validation happens before constructing a manifest URL.
    from ovh_deploy_evidence_contract import RELEASE_ID
    if not isinstance(release_id, str) or not RELEASE_ID.fullmatch(release_id):
        raise ValueError("Unsafe release id")
    sha = run["head_sha"]
    item = json_get(API + "/contents/releases/" + release_id + ".json?ref=" + sha, token)
    if item.get("encoding") != "base64" or item.get("type") != "file":
        raise ValueError("Missing immutable release manifest")
    manifest = json.loads(base64.b64decode(item["content"]))
    # The independent validator compares run, evidence, release manifest and artifact inventory.
    return verify(run, evidence, manifest, artifacts)


def latest_review(token):
    payload = json_get(API + "/actions/workflows/" + WORKFLOW +
                       "/runs?event=workflow_dispatch&branch=main&per_page=30", token)
    for run in payload["workflow_runs"]:
        if run.get("status") != "completed" or run.get("conclusion") != "success":
            continue
        # The newest completed successful run must have evidence; never skip a failed
        # verification and silently fall back to an older deployment.
        return inspect_run(run, token)
    raise ValueError("No successfully completed shared release run found")


if __name__ == "__main__":
    token = os.getenv("GITHUB_TOKEN")
    if not token:
        raise SystemExit("GITHUB_TOKEN required (read-only Actions/contents access)")
    try:
        print(json.dumps(latest_review(token), indent=2))
    except Exception as exc:
        print("EVIDENCE NOT VERIFIED:", str(exc), file=sys.stderr)
        sys.exit(1)
