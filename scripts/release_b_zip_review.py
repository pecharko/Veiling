#!/usr/bin/env python3
"""Read-only inspection of GitHub Actions deployment evidence ZIP archives.

The caller must obtain authenticated run/artifact metadata and archive bytes
through GitHub API, and an immutable manifest from the exact run SHA.
Never executes archive content, accesses OVH, or confers independent acceptance.
"""
import json
import re
import sys
import zipfile
from pathlib import Path

from release_b_artifact_contract import reconcile
from release_b_status import RELEASE_ARTIFACT_PREFIXES, RELEASE_WORKFLOW_PATHS, SHA

RELEASE_ID = re.compile(r"^[a-z0-9][a-z0-9-]{2,60}$")
MAX_ZIP = 256 * 1024
MAX_JSON = 128 * 1024

def evidence_artifact(run, artifacts):
    if not isinstance(run, dict) or not isinstance(artifacts, dict):
        raise ValueError("Invalid metadata")
    if run.get("name") not in RELEASE_ARTIFACT_PREFIXES:
        raise ValueError("Unsupported workflow")
    if run.get("path") != RELEASE_WORKFLOW_PATHS[run["name"]]:
        raise ValueError("Wrong workflow path")
    if not isinstance(run.get("repository"), dict) or run["repository"].get("full_name") != "pecharko/Veiling":
        raise ValueError("Wrong repository")
    if run.get("event") != "workflow_dispatch" or run.get("head_branch") != "main":
        raise ValueError("Not a main dispatch")
    if run.get("status") != "completed" or run.get("conclusion") != "success":
        raise ValueError("Incomplete deployment")
    if not isinstance(run.get("head_sha"), str) or not SHA.fullmatch(run["head_sha"]):
        raise ValueError("Invalid run SHA")
    run_id = run.get("id")
    if type(run_id) is not int or run_id <= 0:
        raise ValueError("Invalid run id")
    found = []
    for item in artifacts.get("artifacts", []):
        if not isinstance(item, dict):
            raise ValueError("Invalid artifact metadata")
        if item.get("name") == f"{RELEASE_ARTIFACT_PREFIXES[run['name']]}-evidence-{run_id}":
            found.append(item)
    if len(found) != 1:
        raise ValueError("Ambiguous or missing evidence artifact")
    artifact = found[0]
    if (artifact.get("expired") is not False or
            type(artifact.get("id")) is not int or artifact["id"] <= 0 or
            type(artifact.get("size_in_bytes")) is not int or
            not 0 < artifact["size_in_bytes"] <= MAX_ZIP):
        raise ValueError("Unavailable or oversized artifact")
    binding = artifact.get("workflow_run")
    if not isinstance(binding, dict) or binding.get("id") != run_id:
        raise ValueError("Artifact belongs to a different run")
    return artifact["id"]

def read_evidence_zip(raw):
    if not isinstance(raw, bytes) or not 0 < len(raw) <= MAX_ZIP:
        raise ValueError("Invalid ZIP size")
    import io
    try:
        with zipfile.ZipFile(io.BytesIO(raw)) as archive:
            infos = archive.infolist()
            if len(infos) != 1 or infos[0].filename != "deployment-evidence.json":
                raise ValueError("Unexpected archive members")
            info = infos[0]
            if (info.is_dir() or info.file_size <= 0 or info.file_size > MAX_JSON
                    or info.compress_size <= 0 or info.compress_size > MAX_ZIP
                    or info.flag_bits & 1 or info.file_size > 50 * info.compress_size):
                raise ValueError("Unsafe archive member")
            data = archive.read(info)
            if len(data) != info.file_size:
                raise ValueError("Truncated archive content")
    except (zipfile.BadZipFile, RuntimeError, OSError) as exc:
        raise ValueError("Invalid ZIP archive") from exc
    try:
        payload = json.loads(data.decode("utf-8"))
    except (UnicodeError, json.JSONDecodeError) as exc:
        raise ValueError("Invalid evidence JSON") from exc
    if not isinstance(payload, dict) or not isinstance(payload.get("release_id"), str):
        raise ValueError("Missing release id")
    if not RELEASE_ID.fullmatch(payload["release_id"]):
        raise ValueError("Invalid release id")
    return payload

def main():
    # CLI uses only local JSON and bounded ZIP input. Its output remains advisory.
    if len(sys.argv) not in (4, 5):
        raise ValueError("Usage: release_b_zip_review.py run.json artifacts.json evidence.zip [manifest.json]")
    run = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
    artifacts = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
    artifact_id = evidence_artifact(run, artifacts)
    with Path(sys.argv[3]).open("rb") as stream:
        bounded_archive = stream.read(MAX_ZIP + 1)
    evidence = read_evidence_zip(bounded_archive)
    if len(sys.argv) == 4:
        print(json.dumps({"artifact_id": artifact_id, "release_id": evidence["release_id"],
                          "status": "MANIFEST_REQUIRED", "release_administration": "OPEN"}, sort_keys=True))
        return
    manifest = json.loads(Path(sys.argv[4]).read_text(encoding="utf-8"))
    result = reconcile(run, evidence, manifest, evidence["release_id"])
    result["artifact_id"] = artifact_id
    print(json.dumps(result, sort_keys=True))

if __name__ == "__main__":
    main()
