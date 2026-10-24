#!/usr/bin/env python3
"""Read-only authorization integration test: no GitHub comments, issues writes or dispatch."""
import json
import os
import re
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))
import verified_deploy_controller as controller

def main():
    repo = os.environ["GITHUB_REPOSITORY"]
    run_id = os.environ["TEST_PREFLIGHT_RUN_ID"]
    if not re.fullmatch(r"[1-9][0-9]*", run_id):
        raise ValueError("Invalid preflight run ID")
    run = controller.gh(f"repos/{repo}/actions/runs/{run_id}")
    artifacts = controller.gh(f"repos/{repo}/actions/runs/{run_id}/artifacts")["artifacts"]
    matches = [a for a in artifacts if a["name"] == f"kavel-preflight-sha256-{run_id}" and not a["expired"]]
    if len(matches) != 1:
        raise ValueError("Missing unique unexpired preflight artifact")
    manifest = json.loads(controller.download_artifact(repo, matches[0]["id"]))
    head = controller.gh(f"repos/{repo}/branches/main")["commit"]["sha"]
    fields = [f"{k}={v}" for k, v in zip(controller.FIELDS[2:], (manifest["sha256"][p] for p in controller.PATHS))]
    approval = "\n".join([controller.PREFIX, f"release_sha={head}", f"preflight_run_id={run_id}", *fields])
    controller.validate(approval, manifest, run, head)
    bad = approval.replace(fields[0], f"{controller.FIELDS[2]}={'0'*64}")
    try:
        controller.validate(bad, manifest, run, head)
    except ValueError:
        pass
    else:
        raise AssertionError("Altered checksum accepted")
    print("PASS: real artifact and synthetic approval validated; invalid checksum rejected")
    print("READ ONLY: no issue comment, no issue mutation, no workflow dispatch")

if __name__ == "__main__":
    main()
