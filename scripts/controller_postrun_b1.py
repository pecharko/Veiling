#!/usr/bin/env python3
"""B1 passive post-run reporter. Read-only GitHub evidence; never deploy."""
import io
import json
import os
import re
import sys
import zipfile
from pathlib import Path

from controller_evidence_collector import BASE, MAX_ZIP, collect, get_bytes, get_json
from ovh_deploy_evidence_contract import RELEASE_ID, validate

EXPECTED_WORKFLOW = ".github/workflows/shared-ovh-release.yml"


def discover(run_id, sha, token, *, read_json=get_json, read_bytes=get_bytes,
             manifests=Path("releases")):
    if type(run_id) is not int or run_id < 1 or not re.fullmatch(r"[a-f0-9]{40}", sha):
        raise ValueError("Invalid immutable run identity")
    run = read_json("/actions/runs/" + str(run_id), token)
    if (run.get("id") != run_id or run.get("head_sha") != sha or
            run.get("path") != EXPECTED_WORKFLOW or
            run.get("event") != "workflow_dispatch" or
            run.get("head_branch") != "main" or
            run.get("status") != "completed"):
        raise ValueError("Untrusted workflow-run identity")
    page = read_json("/actions/runs/" + str(run_id) + "/artifacts?per_page=100", token)
    if type(page.get("total_count")) is not int or page["total_count"] > 100:
        raise ValueError("Incomplete artifact page")
    artifacts = page.get("artifacts")
    if not isinstance(artifacts, list) or len(artifacts) != page["total_count"]:
        raise ValueError("Incomplete artifact inventory")
    matches = [a for a in artifacts if a.get("name") == "shared-ovh-evidence-" + str(run_id)]
    if len(matches) != 1 or matches[0].get("expired") is not False:
        raise ValueError("Missing or expired release evidence")
    artifact = matches[0]
    if (type(artifact.get("id")) is not int or
            artifact.get("workflow_run", {}).get("id") != run_id):
        raise ValueError("Unbound evidence artifact")
    raw = read_bytes(BASE + "/actions/artifacts/" + str(artifact["id"]) + "/zip",
                     token, limit=MAX_ZIP)
    with zipfile.ZipFile(io.BytesIO(raw)) as archive:
        members = archive.infolist()
        if (len(members) != 1 or members[0].filename != "deployment-evidence.json"
                or members[0].file_size > 1024 * 1024 or members[0].is_dir()):
            raise ValueError("Untrusted evidence archive")
        document = validate(json.loads(archive.read(members[0])))
    if document["run_id"] != run_id or document["release_sha"] != sha:
        raise ValueError("Evidence identity mismatch")
    release_id = document["release_id"]
    if not RELEASE_ID.fullmatch(release_id):
        raise ValueError("Unsafe release ID")
    manifest = json.loads((manifests / (release_id + ".json")).read_text(encoding="utf-8"))
    if (set(manifest) != {"schema", "files"} or manifest["schema"] != 1 or
            not isinstance(manifest["files"], list)):
        raise ValueError("Invalid reviewed release manifest")
    expected = [entry["path"] for entry in manifest["files"]]
    if (not expected or len(expected) > 25 or len(set(expected)) != len(expected)
            or expected != [entry["path"] for entry in document["files"]]):
        raise ValueError("Manifest allowlist mismatch")
    return expected


def main():
    try:
        run_id = int(os.environ["RELEASE_RUN_ID"])
        sha = os.environ["RELEASE_SHA"]
        token = os.environ["GITHUB_TOKEN"]
        paths = discover(run_id, sha, token)
        report = collect(run_id, sha, paths, token)
        print(json.dumps({"b1": "READ_ONLY", "run_id": run_id, "report": report},
                         sort_keys=True))
    except Exception as exc:
        print(json.dumps({"b1": "INCOMPLETE", "reason": type(exc).__name__ + ": " + str(exc)}))
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
