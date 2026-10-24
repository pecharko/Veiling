#!/usr/bin/env python3
"""Read-only GitHub API integration test. Never dispatches or writes to Issues."""
import json
import os
import re
import sys
from datetime import datetime, timezone
from verified_deploy_controller import PREFLIGHT, PATHS, download_artifact, gh

def main():
    repo = os.environ["REPOSITORY"]
    run_id = os.environ["PREFLIGHT_RUN_ID"]
    if not re.fullmatch(r"[1-9][0-9]*", run_id):
        raise ValueError("Invalid run ID")
    # Check actual token read access on the issue and Actions endpoints.
    issue = gh(f"repos/{repo}/issues/129")
    if issue["number"] != 129:
        raise ValueError("Issue read permission check failed")
    run = gh(f"repos/{repo}/actions/runs/{run_id}")
    if (run["id"] != int(run_id) or run["path"] != PREFLIGHT or
            run["conclusion"] != "success" or run["status"] != "completed"):
        raise ValueError("Run is not a successful preflight")
    artifacts = gh(f"repos/{repo}/actions/runs/{run_id}/artifacts")["artifacts"]
    matches = [a for a in artifacts if a["name"] == f"kavel-preflight-sha256-{run_id}" and not a["expired"]]
    if len(matches) != 1:
        raise ValueError("No single unexpired preflight artifact; run a new read-only preflight")
    manifest = json.loads(download_artifact(repo, matches[0]["id"]))
    if (set(manifest) != {"schema", "run_id", "head_sha", "captured_at", "sha256"} or
            manifest["schema"] != 1 or manifest["run_id"] != run["id"] or
            manifest["head_sha"] != run["head_sha"] or
            set(manifest["sha256"]) != set(PATHS) or
            any(not re.fullmatch(r"[0-9a-f]{64}", v) for v in manifest["sha256"].values())):
        raise ValueError("Unexpected manifest identity or hashes")
    captured = datetime.fromisoformat(manifest["captured_at"].replace("Z", "+00:00"))
    created = datetime.fromisoformat(run["created_at"].replace("Z", "+00:00"))
    if abs((captured - created).total_seconds()) > 900:
        raise ValueError("Manifest creation time differs from workflow run")
    print("PASS: GitHub token read permissions, real artifact download and run identity")
    print("READ ONLY: no deploy, no issue modification, no dispatch")

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"STOP: {exc}", file=sys.stderr)
        sys.exit(1)
