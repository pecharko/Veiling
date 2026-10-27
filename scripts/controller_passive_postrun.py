#!/usr/bin/env python3
"""B1 passive follow-up of one completed shared OVH release, never deploys."""
import argparse
import base64
import json
import os
import re
from controller_evidence_collector import BASE, MAX_JSON, MAX_ZIP, collect, get_bytes, get_json
from ovh_deploy_evidence_contract import validate

RELEASE = ".github/workflows/shared-ovh-release.yml"
SHA = re.compile(r"^[0-9a-f]{40}$")
ID = re.compile(r"^[a-z0-9][a-z0-9-]{2,60}$")


def follow(run_id, release_sha, token, *, read_json=get_json, read_bytes=get_bytes,
           collector=collect):
    if type(run_id) is not int or run_id <= 0 or not SHA.fullmatch(release_sha):
        raise ValueError("Invalid run binding")
    run = read_json("/actions/runs/" + str(run_id), token)
    if (run.get("id") != run_id or run.get("head_sha") != release_sha
            or run.get("event") != "workflow_dispatch"
            or run.get("head_branch") != "main"
            or run.get("path") not in (RELEASE, "pecharko/Veiling/" + RELEASE)
            or run.get("status") != "completed"):
        raise ValueError("Not a completed, exact shared main release")
    artifact_page = read_json("/actions/runs/" + str(run_id) + "/artifacts?per_page=100", token)
    if artifact_page.get("total_count", 0) > 100:
        raise ValueError("Unbounded artifact inventory")
    matches = [a for a in artifact_page.get("artifacts", []) if
               a.get("name") == "shared-ovh-evidence-" + str(run_id) and a.get("expired") is False]
    if len(matches) != 1 or type(matches[0].get("id")) is not int:
        return {"fully_accepted": False, "status": "INCOMPLETE",
                "reason": "missing_or_duplicate_evidence_artifact"}
    import io
    import zipfile
    raw = read_bytes(BASE + "/actions/artifacts/" + str(matches[0]["id"]) + "/zip",
                     token, limit=MAX_ZIP)
    with zipfile.ZipFile(io.BytesIO(raw)) as archive:
        members = archive.infolist()
        if len(members) != 1 or members[0].filename != "deployment-evidence.json" or members[0].file_size > MAX_JSON:
            raise ValueError("Unexpected evidence ZIP content")
        document = validate(json.loads(archive.read(members[0])))
    if document["run_id"] != run_id or document["release_sha"] != release_sha:
        raise ValueError("Evidence bound to a different release")
    release_id = document["release_id"]
    if not ID.fullmatch(release_id):
        raise ValueError("Invalid release id")
    import urllib.parse
    content = read_json("/contents/releases/" + release_id + ".json?ref=" + release_sha, token)
    if content.get("encoding") != "base64" or content.get("type") != "file":
        raise ValueError("Missing immutable release manifest")
    manifest = json.loads(base64.b64decode(content["content"], validate=False))
    if set(manifest) != {"schema", "files"} or manifest["schema"] != 1:
        raise ValueError("Invalid immutable manifest")
    entries = manifest["files"]
    if not isinstance(entries, list) or not 1 <= len(entries) <= 25:
        raise ValueError("Invalid immutable manifest files")
    paths = []
    for item in entries:
        if not isinstance(item, dict) or set(item) != {"path", "source_sha256", "remote_sha256"}:
            raise ValueError("Invalid manifest entry")
        if not isinstance(item["path"], str) or not item["path"].startswith(("private/", "catalogus-public/")):
            raise ValueError("Unsafe manifest path")
        paths.append(item["path"])
    if len(paths) != len(set(paths)):
        raise ValueError("Duplicate immutable manifest path")
    if [x["path"] for x in document["files"]] != paths:
        raise ValueError("Evidence disagrees with immutable manifest")
    return collector(run_id, release_sha, paths, token)


def main():
    parser = argparse.ArgumentParser(description="Passive read-only shared release report")
    parser.add_argument("--run-id", type=int, required=True)
    parser.add_argument("--release-sha", required=True)
    args = parser.parse_args()
    token = os.environ.get("GITHUB_TOKEN")
    if not token:
        parser.error("A token with Actions:read and Contents:read is required")
    output = follow(args.run_id, args.release_sha, token)
    print(json.dumps(output, sort_keys=True, indent=2))


if __name__ == "__main__":
    main()
