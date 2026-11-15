#!/usr/bin/env python3
"""Read-only post-release triage; never authorizes deploy or acceptance.

Input is GitHub's authenticated Actions run metadata and artifact listing.
Artifact *names* are not evidence of contents or independent OVH verification.
"""
import json
import re
import sys

SHA = re.compile(r"^[0-9a-f]{40}$")
RELEASE_WORKFLOW = "Shared OVH manifest release with rollback"

def summarize(run, artifacts):
    if not isinstance(run, dict) or not isinstance(artifacts, dict):
        raise ValueError("Expected GitHub API objects")
    required = ("id", "name", "event", "head_sha", "head_branch", "status", "conclusion")
    if any(k not in run for k in required):
        raise ValueError("Missing run metadata")
    run_id = run["id"]
    if type(run_id) is not int or run_id <= 0 or not isinstance(run["head_sha"], str) or not SHA.fullmatch(run["head_sha"]):
        raise ValueError("Invalid run identity")
    if run["name"] != RELEASE_WORKFLOW or run["event"] != "workflow_dispatch" or run["head_branch"] != "main":
        raise ValueError("Not the shared production deployment run")
    if not isinstance(artifacts.get("artifacts"), list):
        raise ValueError("Missing artifact listing")
    if any(not isinstance(a, dict) for a in artifacts["artifacts"]):
        raise ValueError("Invalid artifact listing")
    found = set()
    for a in artifacts["artifacts"]:
        if a.get("expired") is False and a.get("name") in (
            f"shared-ovh-evidence-{run_id}", f"shared-ovh-rollback-{run_id}"
        ):
            found.add(a["name"])
    completed = run["status"] == "completed"
    success = completed and run["conclusion"] == "success"
    evidence_present = f"shared-ovh-evidence-{run_id}" in found
    rollback_present = f"shared-ovh-rollback-{run_id}" in found
    state = "RUN_FAILED_OR_INCOMPLETE"
    if success:
        state = "REVIEW_ARTIFACT_CONTENTS" if evidence_present and rollback_present else "INCOMPLETE_ARTIFACTS"
    return {
        "run_id": run_id,
        "release_sha": run["head_sha"],
        "status": state,
        "deployment_job_success": success,
        "evidence_artifact_available": evidence_present,
        "rollback_artifact_available": rollback_present,
        "deployment_contents_verified": False,
        "independent_ovh_readback": "PENDING",
        "browser_acceptance": "PENDING",
        "release_administration": "OPEN",
        "next_action": "Independently authenticate artifact contents, OVH hashes and browser acceptance before closing",
    }

def main():
    data = json.load(sys.stdin)
    report = summarize(data["run"], data["artifacts"])
    print("## Post-release status (read-only; not acceptance)")
    print()
    for key, value in report.items():
        print(f"- **{key}**: `{value}`")
    print()
    print("No approval, deployment, independent OVH readback or browser acceptance is inferred.")

if __name__ == "__main__":
    main()
