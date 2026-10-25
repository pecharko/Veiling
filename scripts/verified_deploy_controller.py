#!/usr/bin/env python3
"""One-shot, fail-closed GitHub issue approvals for registered release workflows."""
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


# Every supported release requires an explicitly reviewed registry entry. New
# workflows are NEVER implicitly trusted because their name contains "release".
REGISTRY_PATH = "scripts/verified_deploy_registry.json"


def registered_release(text):
    """Return a strict registry entry, or None for the legacy approval format."""
    lines = text.replace("\r", "").strip().split("\n")
    if not lines or lines[0] != "DEPLOY VERIFIED RELEASE":
        return None
    if len(lines) < 5 or not lines[1].startswith("workflow="):
        raise ValueError("Invalid registered approval")
    workflow = lines[1].partition("=")[2]
    if not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9_-]*\.yml", workflow):
        raise ValueError("Invalid workflow filename")
    with open(REGISTRY_PATH, encoding="utf-8") as file:
        registry = json.load(file)
    if set(registry) != {"schema", "releases"} or registry["schema"] != 1:
        raise ValueError("Invalid release registry")
    config = registry["releases"].get(workflow)
    if not isinstance(config, dict):
        raise ValueError("Workflow not approved in release registry")
    if set(config) != {"preflight", "artifact_prefix", "confirmation", "paths", "hash_inputs"}:
        raise ValueError("Invalid registry entry")
    paths, inputs = config["paths"], config["hash_inputs"]
    if (not isinstance(paths, list) or not isinstance(inputs, list) or
            not paths or len(paths) != len(inputs) or
            len(paths) != len(set(paths)) or len(inputs) != len(set(inputs)) or
            any(not re.fullmatch(r"[a-zA-Z0-9_]+", x) for x in inputs) or
            any(not isinstance(x, str) or not re.fullmatch(
                r"(catalogus-public|private)/[a-zA-Z0-9_./-]+", x) or ".." in x.split("/")
                for x in paths)):
        raise ValueError("Invalid registry paths or inputs")
    if (not re.fullmatch(r"[a-zA-Z0-9_-]+", config["artifact_prefix"]) or
            not re.fullmatch(r"\.github/workflows/[a-zA-Z0-9_-]+\.yml", config["preflight"]) or
            not isinstance(config["confirmation"], str) or
            not re.fullmatch(r"DEPLOY [A-Z0-9 _-]{3,100}", config["confirmation"])):
        raise ValueError("Invalid registered release metadata")
    return workflow, config


def parse_registered_approval(text, workflow, config):
    lines = text.replace("\r", "").strip().split("\n")
    keys = ["workflow", "release_sha", "preflight_run_id", *config["hash_inputs"]]
    if len(lines) != len(keys) + 1 or lines[0] != "DEPLOY VERIFIED RELEASE":
        raise ValueError("Invalid approval field count")
    data = {}
    for key, line in zip(keys, lines[1:]):
        if not line.startswith(key + "="):
            raise ValueError("Invalid field order: " + key)
        data[key] = line[len(key) + 1:]
    if data["workflow"] != workflow:
        raise ValueError("Workflow mismatch")
    return data


def validate_registered(text, manifest, run, main_sha, workflow, config):
    data = parse_registered_approval(text, workflow, config)
    if not re.fullmatch(r"[0-9a-f]{40}", data["release_sha"]):
        raise ValueError("Invalid SHA")
    if not re.fullmatch(r"[1-9][0-9]*", data["preflight_run_id"]):
        raise ValueError("Invalid preflight ID")
    if any(not re.fullmatch(r"[0-9a-f]{64}", data[key]) for key in config["hash_inputs"]):
        raise ValueError("Invalid remote hash")
    expected = dict(zip(config["paths"], (data[key] for key in config["hash_inputs"])))
    if (data["release_sha"] != main_sha or run["head_sha"] != main_sha or
            run["id"] != int(data["preflight_run_id"]) or
            run["path"] != config["preflight"] or
            run["conclusion"] != "success" or run["status"] != "completed"):
        raise ValueError("Release SHA or preflight mismatch")
    created = datetime.fromisoformat(run["created_at"].replace("Z", "+00:00"))
    if not 0 <= (datetime.now(timezone.utc) - created).total_seconds() <= 86400:
        raise ValueError("Preflight expired")
    if (set(manifest) != {"schema", "run_id", "head_sha", "captured_at", "sha256"} or
            manifest["schema"] != 1 or manifest["run_id"] != run["id"] or
            manifest["head_sha"] != main_sha or manifest["sha256"] != expected):
        raise ValueError("Artifact mismatch")
    captured = datetime.fromisoformat(manifest["captured_at"].replace("Z", "+00:00"))
    if abs((captured - created).total_seconds()) > 900:
        raise ValueError("Artifact timestamp mismatch")
    return data



def gh(*args, binary=False):
    result = subprocess.run(("gh", "api", *args), check=True, capture_output=True)
    return result.stdout if binary else json.loads(result.stdout)



def download_artifact(repo, artifact_id):
    """Download a named workflow artifact using the GitHub CLI binary-safe path."""
    import tempfile
    with tempfile.TemporaryDirectory() as directory:
        metadata = gh(f"repos/{repo}/actions/artifacts/{artifact_id}")
        run_id = metadata["workflow_run"]["id"]
        subprocess.run(("gh", "run", "download", str(run_id), "--repo", repo,
                        "--name", metadata["name"], "--dir", directory),
                       check=True, capture_output=True)
        manifest_file = os.path.join(directory, "preflight-sha256.json")
        raw = open(manifest_file, "rb").read(1024 * 1024 + 1)
        if len(raw) > 1024 * 1024:
            raise ValueError("Artifact exceeds 1 MiB")
        return raw


def has_closed_event(repo, issue_number):
    """An issue that has EVER been closed cannot authorize another deploy."""
    result = subprocess.run(("gh", "api", f"repos/{repo}/issues/{issue_number}/events",
                             "--paginate", "--jq", ".[].event"),
                            check=True, capture_output=True, text=True)
    return "closed" in result.stdout.splitlines()


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


def dispatch_approved(command):
    """Never retry a dispatch after consuming its authorization."""
    try:
        subprocess.run(command, check=True)
    except subprocess.CalledProcessError as exc:
        raise RuntimeError(
            "Dispatch failed after approval was consumed. Do not reopen issue; "
            "check GitHub Actions runs before creating a fresh approval."
        ) from exc


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
    # GitHub issue events are append-only. A previously closed issue may be
    # reopened, but must never authorize a second deployment.
    if has_closed_event(repo, issue_number):
        raise ValueError("Approval issue has already been consumed or history is unavailable")
    main_sha = gh(f"repos/{repo}/branches/main")["commit"]["sha"]
    text = os.environ["APPROVAL_TEXT"]
    registration = registered_release(text)
    fields = text.replace("\r", "").strip().split("\n")
    if registration is not None:
        workflow, config = registration
        parsed = parse_registered_approval(text, workflow, config)
        rid = parsed["preflight_run_id"]
    else:
        if len(fields) != 7 or not fields[2].startswith("preflight_run_id="):
            raise ValueError("Invalid approval")
        rid = fields[2].partition("=")[2]
    if not re.fullmatch(r"[1-9][0-9]*", rid):
        raise ValueError("Invalid run ID")
    run = gh(f"repos/{repo}/actions/runs/{rid}")
    artifacts = gh(f"repos/{repo}/actions/runs/{rid}/artifacts")["artifacts"]
    artifact_name = (f"{config['artifact_prefix']}-{rid}" if registration is not None
                     else f"kavel-preflight-sha256-{rid}")
    matches = [a for a in artifacts if a["name"] == artifact_name and not a["expired"]]
    if len(matches) != 1:
        raise ValueError("Expected a single unexpired manifest artifact")
    manifest = json.loads(download_artifact(repo, matches[0]["id"]))
    data = (validate_registered(text, manifest, run, main_sha, workflow, config)
            if registration is not None else validate(text, manifest, run, main_sha))
    # Dry run checks the complete authorization evidence but never consumes approval.
    if os.environ.get("CONTROLLER_DRY_RUN", "false") == "true":
        print("DRY RUN PASS: authorization and artifact verified; no issue write or dispatch")
        return
    # Serial controller concurrency + closing the issue before dispatch prevents replay.
    # Fail closed on a stale or already-consumed approval before dispatch.
    fresh = gh(f"repos/{repo}/issues/{issue_number}")
    if has_closed_event(repo, issue_number):
        raise ValueError("Approval already consumed in immutable issue history")
    if fresh["state"] != "open":
        raise ValueError("Approval already consumed")
    gh(f"repos/{repo}/issues/{issue_number}", "-X", "PATCH",
       "-f", "state=closed", "-f", "state_reason=completed")
    target_workflow = workflow if registration is not None else WORKFLOW
    confirmation = config["confirmation"] if registration is not None else PREFIX
    hash_keys = config["hash_inputs"] if registration is not None else FIELDS[2:]
    command = ("gh", "workflow", "run", target_workflow, "--repo", repo, "--ref", "main",
               "-f", "mode=deploy", "-f", f"release_sha={data['release_sha']}",
               "-f", f"confirmation={confirmation}")
    command += tuple(item for key in hash_keys for item in ("-f", f"{key}={data[key]}"))
    dispatch_approved(command)
    print("Approved registered deployment dispatched once; approval issue closed.")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("STOP: " + str(exc), file=sys.stderr)
        sys.exit(1)
