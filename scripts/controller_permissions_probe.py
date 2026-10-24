#!/usr/bin/env python3
"""Isolated GitHub write/dispatch permission probe. NEVER imports production controller."""
import json
import os
import re
import subprocess

TARGET = "controller-harmless-target.yml"

def gh(*args):
    return subprocess.run(("gh", *args), capture_output=True, text=True, check=True)

def main():
    repo = os.environ["GITHUB_REPOSITORY"]
    nonce = os.environ["TEST_NONCE"]
    if repo != "pecharko/Veiling" or not re.fullmatch(r"[a-zA-Z0-9_-]{8,40}", nonce):
        raise ValueError("Unexpected repository or invalid nonce")
    title = "CONTROLLER-E2E-TEST: " + nonce
    issue = json.loads(gh("api", f"repos/{repo}/issues", "-X", "POST",
                          "-f", f"title={title}",
                          "-f", "body=Temporary test issue for isolated GitHub Actions permission validation; no deploy authorization.").stdout)
    number = issue["number"]
    print(f"Created isolated test issue #{number}")
    try:
        gh("workflow", "run", TARGET, "--repo", repo, "--ref", "main", "-f", f"test_nonce={nonce}")
        print("Harmless workflow dispatch accepted")
    finally:
        gh("api", f"repos/{repo}/issues/{number}", "-X", "PATCH",
           "-f", "state=closed", "-f", "state_reason=completed")
        print(f"Closed test issue #{number}")
    print("PASS: issue create/close and harmless workflow dispatch; no production endpoint touched")

if __name__ == "__main__":
    main()
