#!/usr/bin/env python3
"""Read-only controller integration entrypoint; never consumes approvals or dispatches."""
import json
import os
import re
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import verified_deploy_controller as controller

def fetch(path):
    completed = subprocess.run(["gh", "api", path], check=True, capture_output=True, text=True)
    return json.loads(completed.stdout)

def main():
    repo = os.environ["GITHUB_REPOSITORY"]
    owner = repo.split("/", 1)[0]
    issue_number = os.environ["TEST_ISSUE_NUMBER"]
    comment_id = os.environ["TEST_COMMENT_ID"]
    if not re.fullmatch(r"[1-9][0-9]*", issue_number) or not re.fullmatch(r"[1-9][0-9]*", comment_id):
        raise ValueError("Invalid issue/comment identifier")
    comment = fetch(f"repos/{repo}/issues/comments/{comment_id}")
    if (comment["user"]["login"] != owner or
            comment["issue_url"].rstrip("/") != f"https://api.github.com/repos/{repo}/issues/{issue_number}"):
        raise ValueError("Comment is not an owner approval on this issue")
    os.environ.update(REPOSITORY=repo, ISSUE_NUMBER=issue_number, ACTOR=owner,
                      APPROVAL_TEXT=comment["body"], CONTROLLER_DRY_RUN="true")
    controller.main()
    print("READ ONLY: controller validated, approval not consumed, no dispatch")

if __name__ == "__main__":
    main()
