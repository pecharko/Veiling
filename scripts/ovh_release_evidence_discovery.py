#!/usr/bin/env python3
"""Read-only GitHub API discovery of evidence from the latest shared OVH deployment.

No workflow_dispatch, no GitHub mutations, no OVH access. Requires GITHUB_TOKEN.
"""
import base64
import io
import json
import os
import re
import sys
import urllib.request
from urllib.parse import urlsplit
import zipfile

from ovh_release_evidence_review import verify

API = "https://api.github.com/repos/pecharko/Veiling"
WORKFLOW = "shared-ovh-release.yml"
MAX_ZIP = 2 * 1024 * 1024
MAX_JSON = 256 * 1024

class ArtifactRedirect(urllib.request.HTTPRedirectHandler):
    """Follow GitHub artifact redirects only over HTTPS, without the API token."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        original = urlsplit(req.full_url)
        target = urlsplit(newurl)
        host = (target.hostname or "").lower()
        allowed_host = (
            host == "objects.githubusercontent.com"
            or host.endswith(".actions.githubusercontent.com")
            or host.endswith(".blob.core.windows.net")
            or host.endswith(".s3.amazonaws.com")
        )
        if (code not in (302, 303) or original.scheme != "https"
                or original.hostname != "api.github.com"
                or not re.fullmatch(
                    r"/repos/pecharko/Veiling/actions/artifacts/[0-9]+/zip",
                    original.path
                ) or target.scheme != "https" or not allowed_host
                or target.username is not None or target.password is not None
                or target.port not in (None, 443)):
            raise ValueError("Untrusted GitHub artifact redirect")
        # Explicit new headers: NEVER forward Authorization or GitHub API token.
        return urllib.request.Request(newurl, headers={
            "Accept": "application/octet-stream"
        }, method="GET")


def get(url, token):
    req = urllib.request.Request(url, headers={
        "Authorization": "Bearer " + token,
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
    })
    # GitHub responds with an HTTPS redirect for artifact ZIP downloads.
    # The custom redirect handler must remove Authorization before following it.
    opener = urllib.request.build_opener(ArtifactRedirect())
    with opener.open(req, timeout=20) as response:
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
    if type(run_id) is not int or run_id <= 0:
        raise ValueError("Invalid GitHub run ID")
    artifacts = json_get(API + "/actions/runs/" + str(run_id) + "/artifacts?per_page=100", token)["artifacts"]
    match = [a for a in artifacts if a.get("name") == "shared-ovh-evidence-" + str(run_id)
             and a.get("expired") is False]
    if len(match) != 1:
        raise ValueError("Evidence artifact not available")
    artifact_id = match[0].get("id")
    if type(artifact_id) is not int or artifact_id <= 0:
        raise ValueError("Invalid evidence artifact ID")
    evidence = read_evidence_zip(
        get(API + "/actions/artifacts/" + str(artifact_id) + "/zip", token)
    )
    release_id = evidence.get("release_id", "")
    # Bound path validation happens before constructing a manifest URL.
    from ovh_deploy_evidence_contract import RELEASE_ID
    if not isinstance(release_id, str) or not RELEASE_ID.fullmatch(release_id):
        raise ValueError("Unsafe release id")
    from ovh_release_evidence_review import SHA
    sha = run["head_sha"]
    if not isinstance(sha, str) or not SHA.fullmatch(sha):
        raise ValueError("Invalid immutable release SHA")
    item = json_get(API + "/contents/releases/" + release_id + ".json?ref=" + sha, token)
    if item.get("encoding") != "base64" or item.get("type") != "file":
        raise ValueError("Missing immutable release manifest")
    manifest = json.loads(base64.b64decode(item["content"]))
    # The independent validator compares run, evidence, release manifest and artifact inventory.
    return verify(run, evidence, manifest, artifacts)


def latest_review(token):
    payload = json_get(API + "/actions/workflows/" + WORKFLOW +
                       "/runs?event=workflow_dispatch&branch=main&per_page=30", token)
    runs = payload.get("workflow_runs")
    if not isinstance(runs, list) or not runs:
        raise ValueError("No shared release dispatch found")
    # GitHub lists workflow runs newest first. Never report an older success as
    # current green when the latest release failed, was cancelled, or is running.
    run = runs[0]
    if run.get("status") != "completed" or run.get("conclusion") != "success":
        raise ValueError("Latest shared release dispatch is not successful")
    return inspect_run(run, token)


if __name__ == "__main__":
    token = os.getenv("GITHUB_TOKEN")
    if not token:
        raise SystemExit("GITHUB_TOKEN required (read-only Actions/contents access)")
    try:
        print(json.dumps(latest_review(token), indent=2))
    except Exception as exc:
        print("EVIDENCE NOT VERIFIED:", str(exc), file=sys.stderr)
        sys.exit(1)
