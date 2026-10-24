#!/usr/bin/env python3
"""One-shot, fail-closed GitHub issue approval for the existing rollback release."""
import io
import json
import os
import re
import subprocess
import sys
import zipfile
from datetime import datetime, timezone

PREFIX = "DEPLOY KAVELNUMMER THUMBNAILS WITH ROLLBACK"
PATHS = (
    "catalogus-public/admin/kavel.php",
    "private/lot_form.php",
    "catalogus-public/admin/admin.css",
    "catalogus-public/admin/index.php",
)
FIELDS = ("release_sha", "preflight_run_id", "expected_kavel",
          "expected_lot_form", "expected_css", "expected_index")
WORKFLOW = "kavelnummer-thumbnails-release.yml"
PREFLIGHT = ".github/workflows/kavel-release-readonly-preflight.yml"


def gh(*args, binary=False):
    result = subprocess.run(("gh", "api", *args), check=True, capture_output=True)
    return result.stdout if binary else json.loads(result.stdout)


def validate(text, manifest, run, main_sha):
    lines = text.replace("\r", "").strip().split("\n")
    if len(lines) != 7 or lines[0] != PREFIX:
        raise ValueError("Invalid approval format")
    data = {}
    for key, line in zip(FIELDS, lines[1:]):
        if not line.startswith(key + "="):
            raise ValueError("Invalid approval field: " + key)
        data[key] = line[len(key) + 1:]
    if not re.fullmatch(r"[0-9a-f]{40}", data["release_sha"]):
        raise ValueError("Invalid commit")
    if not re.fullmatch(r"[1-9][0-9]*", data["preflight_run_id"]):
        raise ValueError("Invalid preflight run")
    if any(not re.fullmatch(r"[0-9a-f]{64}", data[key]) for key in FIELDS[2:]):
        raise ValueError("Invalid digest")
    expected = dict(zip(PATHS, (data[k] for k in FIELDS[2:])))
    if data["release_sha"] != main_sha or run["head_sha"] != main_sha:
        raise ValueError("Release no longer equals main")
    if (run["id"] != int(data["preflight_run_id"]) or
            run["path"] != PREFLIGHT or run["conclusion"] != "success" or
            run["status"] != "completed"):
        raise ValueError("Preflight run mismatch")
    created = datetime.fromisoformat(run["created_at"].replace("Z", "+00:00"))
    age = (datetime.now(timezone.utc) - created).total_seconds()
    if age < 0 or age > 86400:
        raise ValueError("Preflight expired")
    if (manifest.get("schema") != 1 or
            manifest.get("run_id") != run["id"] or
            manifest.get("head_sha") != main_sha or
            manifest.get("sha256") != expected):
        raise ValueError("Remote artifact hashes do not match approval")
    if set(manifest) != {"schema", "run_id", "head_sha", "captured_at", "sha256"}:
        raise ValueError("Unexpected manifest fields")
    captured = datetime.fromisoformat(manifest["captured_at"].replace("Z", "+00:00"))
    if abs((captured - created).total_seconds()) > 900:
        raise ValueError("Manifest time mismatch")
    return data


def main():
    repo = os.environ["REPOSITORY"]
    issue_number = os.environ["ISSUE_NUMBER"]
    owner = repo.split("/")[0]
    if os.environ["ACTOR"] != owner:
        raise ValueError("Only repository owner may approve")
    issue = gh(f"repos/{repo}/issues/{issue_number}")
    if (issue["state"] != "open" or issue["user"]["login"] != owner or
            not issue["title"].startswith("DEPLOY-AUTH:") or
            "approved-deploy-request" not in {label["name"] for label in issue["labels"]}):
        raise ValueError("Not a specially authorized owner-created approval issue")
    main_sha = gh(f"repos/{repo}/branches/main")["commit"]["sha"]
    text = os.environ["APPROVAL_TEXT"]
    fields = text.replace("\r", "").strip().split("\n")
    if len(fields) != 7 or not fields[2].startswith("preflight_run_id="):
        raise ValueError("Invalid approval")
    rid = fields[2].partition("=")[2]
    if not re.fullmatch(r"[1-9][0-9]*", rid):
        raise ValueError("Invalid run ID")
    run = gh(f"repos/{repo}/actions/runs/{rid}")
    artifacts = gh(f"repos/{repo}/actions/runs/{rid}/artifacts")["artifacts"]
    matches = [a for a in artifacts if a["name"] == f"kavel-preflight-sha256-{rid}" and not a["expired"]]
    if len(matches) != 1:
        raise ValueError("Expected a single unexpired manifest artifact")
    archive = gh(f"repos/{repo}/actions/artifacts/{matches[0]['id']}/zip", binary=True)
    with zipfile.ZipFile(io.BytesIO(archive)) as zf:
        if zf.namelist() != ["preflight-sha256.json"]:
            raise ValueError("Unexpected artifact archive contents")
        manifest = json.loads(zf.read("preflight-sha256.json"))
    data = validate(text, manifest, run, main_sha)
    # Serial controller concurrency + closing the issue before dispatch prevents replay.
    gh(f"repos/{repo}/issues/{issue_number}", "-X", "PATCH",
       "-f", "state=closed", "-f", "state_reason=completed")
    command = ("gh", "workflow", "run", WORKFLOW, "--repo", repo, "--ref", "main",
               "-f", "mode=deploy", "-f", f"release_sha={data['release_sha']}",
               "-f", f"confirmation={PREFIX}")
    command += tuple(f"-f{key}={data[key]}" for key in FIELDS[2:])
    subprocess.run(command, check=True)
    print("Approved rollback-enabled deployment dispatched; approval issue closed.")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("STOP: " + str(exc), file=sys.stderr)
        sys.exit(1)
