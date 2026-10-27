#!/usr/bin/env python3
"""Offline, read-only reconciliation of OVH release attestations with GitHub metadata.

Inputs must come from independently retrieved GitHub run/artifact metadata and
an actual downloaded evidence artifact. Never executes or authorizes deploys.
"""
import json
import re
import sys
from pathlib import Path

from ovh_deploy_evidence_contract import validate

RELEASE_ID = re.compile(r"^[a-z0-9][a-z0-9-]{2,60}$")
SHA = re.compile(r"^[0-9a-f]{40}$")


def verify(run, evidence, manifest, artifacts):
    """Return an independently cross-checked technical result, or fail closed."""
    validate(evidence)
    release_id = evidence["release_id"]
    sha = evidence["release_sha"]
    run_id = evidence["run_id"]
    if not RELEASE_ID.fullmatch(release_id) or not SHA.fullmatch(sha):
        raise ValueError("Invalid release identity")
    if (type(run.get("id")) is not int or run["id"] != run_id
            or run.get("head_sha") != sha
            or run.get("event") != "workflow_dispatch"
            or run.get("head_branch") != "main"
            or run.get("status") != "completed"
            or run.get("conclusion") != "success"):
        raise ValueError("Run does not independently prove successful approved main dispatch")
    workflow = run.get("path", "")
    if not isinstance(workflow, str) or not workflow.endswith(
        ".github/workflows/shared-ovh-release.yml"
    ):
        raise ValueError("Not the reviewed shared release workflow")
    if evidence["status"] != "VERIFIED":
        raise ValueError("Attestation does not indicate verified readback")
    if evidence["browser_acceptance"] != "PENDING":
        raise ValueError("Browser acceptance must be separately collected")
    if (not isinstance(manifest, dict) or set(manifest) != {"schema", "files"}
            or type(manifest["schema"]) is not int or manifest["schema"] != 1):
        raise ValueError("Invalid release manifest")
    entries = manifest["files"]
    if not isinstance(entries, list) or len(entries) != len(evidence["files"]):
        raise ValueError("Manifest file count mismatch")
    observed = {row["path"]: row for row in evidence["files"]}
    if len(observed) != len(entries):
        raise ValueError("Repeated attested path")
    for entry in entries:
        if not isinstance(entry, dict) or set(entry) != {
            "path", "source_sha256", "remote_sha256"
        }:
            raise ValueError("Invalid manifest entry")
        path = entry["path"]
        row = observed.get(path)
        if row is None or (row["source_sha256"] != entry["source_sha256"]
                           or row["baseline_sha256"] != entry["remote_sha256"]
                           or row["readback_sha256"] != entry["source_sha256"]):
            raise ValueError("Unreconciled manifest hash or missing readback")
    if not isinstance(artifacts, list):
        raise ValueError("Missing GitHub artifact inventory")
    expected = {
        "shared-ovh-evidence-" + str(run_id),
        "shared-ovh-rollback-" + str(run_id),
    }
    found = set()
    for item in artifacts:
        if not isinstance(item, dict):
            raise ValueError("Invalid artifact")
        if item.get("name") in expected and item.get("expired") is False:
            if type(item.get("id")) is not int or item["id"] <= 0:
                raise ValueError("Missing artifact id")
            found.add(item["name"])
    if found != expected:
        raise ValueError("Missing or expired proof/rollback artifact")
    return {"status": "TECHNICALLY_VERIFIED", "release_id": release_id,
            "run_id": run_id, "release_sha": sha,
            "browser_acceptance": "PENDING",
            "artifact_names": sorted(found)}


if __name__ == "__main__":
    if len(sys.argv) != 5:
        raise SystemExit("Usage: python3 scripts/ovh_release_evidence_review.py RUN_JSON EVIDENCE_JSON MANIFEST_JSON ARTIFACTS_JSON")
    run, evidence, manifest, artifacts = [
        json.loads(Path(p).read_text(encoding="utf-8")) for p in sys.argv[1:]
    ]
    print(json.dumps(verify(run, evidence, manifest, artifacts), indent=2))
