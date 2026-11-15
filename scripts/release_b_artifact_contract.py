#!/usr/bin/env python3
"""Offline consistency check for authenticated deployment artifacts.

CALLER MUST authenticate provenance of the run, artifact bytes and manifest
separately. This validates content only, not independent OVH or browser evidence.
No network, writes, production actions, or acceptance.
"""
import json
import sys

from ovh_deploy_evidence_contract import validate as validate_evidence
from release_b_status import RELEASE_ARTIFACT_PREFIXES, RELEASE_WORKFLOW_PATHS, SHA

def reconcile(run, evidence, manifest, expected_release_id):
    if not isinstance(run, dict) or run.get("name") not in RELEASE_ARTIFACT_PREFIXES:
        raise ValueError("Unsupported deployment workflow")
    if run.get("path") != RELEASE_WORKFLOW_PATHS[run["name"]]:
        raise ValueError("Unrecognized workflow path")
    if not isinstance(run.get("repository"), dict) or run["repository"].get("full_name") != "pecharko/Veiling":
        raise ValueError("Unrecognized repository")
    if run.get("event") != "workflow_dispatch" or run.get("head_branch") != "main":
        raise ValueError("Untrusted event or branch")
    if (not isinstance(run.get("head_sha"), str)
            or not SHA.fullmatch(run["head_sha"])
            or type(run.get("id")) is not int or run["id"] <= 0):
        raise ValueError("Invalid run identity")
    if (run.get("status") != "completed" or run.get("conclusion") != "success"):
        raise ValueError("Deployment run not successful")
    if not isinstance(manifest, dict) or set(manifest) != {"schema", "files"} or manifest["schema"] != 1:
        raise ValueError("Invalid manifest")
    entries = manifest["files"]
    if not isinstance(entries, list) or not entries or len(entries) > 25:
        raise ValueError("Invalid manifest entries")
    if not isinstance(expected_release_id, str) or not expected_release_id:
        raise ValueError("Missing expected release")
    expected = {}
    for entry in entries:
        if not isinstance(entry, dict) or set(entry) != {"path", "source_sha256", "remote_sha256"}:
            raise ValueError("Invalid manifest row")
        path = entry["path"]
        if not isinstance(path, str) or path in expected:
            raise ValueError("Invalid or duplicate path")
        expected[path] = (entry["source_sha256"], entry["remote_sha256"])
    evidence = validate_evidence(evidence)
    if evidence["release_id"] != expected_release_id:
        raise ValueError("Different release id")
    if evidence["release_sha"] != run["head_sha"] or evidence["run_id"] != run["id"]:
        raise ValueError("Evidence belongs to a different run")
    if evidence["status"] != "VERIFIED":
        raise ValueError("Uploader has not verified deployment")
    observed = {item["path"]: (item["source_sha256"], item["baseline_sha256"])
                for item in evidence["files"]}
    if observed != expected:
        raise ValueError("Manifest and uploader evidence disagree")
    return {
        "status": "UPLOADER_ARTIFACT_CONTENT_CONSISTENT_ONLY",
        "run_id": run["id"],
        "release_id": evidence["release_id"],
        "release_sha": evidence["release_sha"],
        "files": len(observed),
        "provenance_authenticated_by_caller": False,
        "independent_ovh_readback": "PENDING",
        "browser_acceptance": "PENDING",
        "release_administration": "OPEN",
    }

def main():
    payload = json.load(sys.stdin)
    if not isinstance(payload, dict) or set(payload) != {"run", "evidence", "manifest", "release_id"}:
        raise ValueError("Invalid reconciliation payload")
    print(json.dumps(reconcile(payload["run"], payload["evidence"], payload["manifest"],
                               payload["release_id"]), sort_keys=True))

if __name__ == "__main__":
    main()
