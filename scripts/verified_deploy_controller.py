#!/usr/bin/env python3
"""One-shot, fail-closed GitHub issue approvals for registered release workflows."""
import io
import json
import os
import re
import subprocess
import sys
import zipfile
from pathlib import Path

# Also support importlib-based isolated regression runs from tests/.
sys.path.insert(0, os.path.dirname(__file__))
from controller_b5_report_consumer import summarize as summarize_b5_report


def inspect_b5_advisory_report(report, *, run_id, head_sha, artifact_name):
    """Optional offline operator review; NEVER used to authorize or dispatch.

    Deliberately not called by main() or the issue_comment workflow. The
    execution gate remains unchanged until separately reviewed and approved.
    """
    review = summarize_b5_report(
        report, run_id=run_id, head_sha=head_sha, artifact_name=artifact_name)
    return {
        "status": review["status"],
        "reason": review["reason"],
        "evidence_scope": "offline_consistency_only",
        "deployment_authorized": False,
        "fully_accepted": False,
        "controller_gate_changed": False,
    }


from lot_sale_controller_adapter import (
    PREFIX as LOT_SALE_PREFIX, WORKFLOW as LOT_SALE_WORKFLOW,
    CONFIRMATION as LOT_SALE_CONFIRMATION, parse_approval as parse_lot_sale,
    verify_plan as verify_lot_sale_plan,
)
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
    if not {"preflight", "artifact_prefix", "confirmation", "paths", "hash_inputs"}.issubset(config) or set(config) - {"preflight", "artifact_prefix", "confirmation", "paths", "hash_inputs", "manifest_file", "allow_absent", "manual_schema_gate", "manual_https_gate"}:
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
    absent = config.get("allow_absent", [])
    if not isinstance(absent, list) or len(absent) != len(set(absent)) or any(p not in paths for p in absent):
        raise ValueError("Invalid allow_absent")
    if not re.fullmatch(r"[a-zA-Z0-9_-]+\.json", config.get("manifest_file", "preflight-sha256.json")):
        raise ValueError("Invalid manifest filename")
    return workflow, config


def parse_registered_approval(text, workflow, config):
    lines = text.replace("\r", "").strip().split("\n")
    keys = ["workflow", "release_sha", "preflight_run_id", *config["hash_inputs"]]
    if config.get("manual_schema_gate") is True:
        keys += ["manual_https_precheck", "migration_confirmed"]
    elif config.get("manual_https_gate") is True:
        keys += ["manual_https_precheck"]
    if len(lines) != len(keys) + 1 or lines[0] != "DEPLOY VERIFIED RELEASE":
        raise ValueError("Invalid approval field count")
    data = {}
    for key, line in zip(keys, lines[1:]):
        if not line.startswith(key + "="):
            raise ValueError("Invalid field order: " + key)
        data[key] = line[len(key) + 1:]
    if data["workflow"] != workflow:
        raise ValueError("Workflow mismatch")
    if config.get("manual_schema_gate") is True:
        if data["manual_https_precheck"] != "VERIFIED-" + data["release_sha"]:
            raise ValueError("Missing exact-SHA manual HTTPS approval")
        if data["migration_confirmed"] != "MIGRATED-admins.is_active":
            raise ValueError("Missing explicit schema migration confirmation")
    elif config.get("manual_https_gate") is True:
        if data["manual_https_precheck"] != "VERIFIED-" + data["release_sha"]:
            raise ValueError("Missing exact-SHA manual HTTPS approval")
    return data


def validate_registered(text, manifest, run, main_sha, workflow, config):
    data = parse_registered_approval(text, workflow, config)
    if not re.fullmatch(r"[0-9a-f]{40}", data["release_sha"]):
        raise ValueError("Invalid SHA")
    if not re.fullmatch(r"[1-9][0-9]*", data["preflight_run_id"]):
        raise ValueError("Invalid preflight ID")
    expected = dict(zip(config["paths"], (data[key] for key in config["hash_inputs"])))
    absent = set(config.get("allow_absent", []))
    if any(not (value == "ABSENT" and path in absent or
                re.fullmatch(r"[0-9a-f]{64}", value)) for path, value in expected.items()):
        raise ValueError("Invalid remote hash")
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



def normalize_registered_manifest(manifest, run, workflow, config):
    """Accept only exact registered newer-format read-only proof."""
    if "manifest_file" not in config:
        return manifest
    if (set(manifest) != {"sha", "run_id", "remote"} or
            manifest["sha"] != run["head_sha"] or
            str(manifest["run_id"]) != str(run["id"]) or
            set(manifest["remote"]) != set(config["paths"])):
        raise ValueError("Invalid registered preflight evidence")
    # validate_registered expects a timestamp; use GitHub's trustworthy run creation time.
    return {"schema": 1, "run_id": run["id"],
            "head_sha": manifest["sha"], "captured_at": run["created_at"],
            "sha256": manifest["remote"]}


def gh(*args, binary=False):
    result = subprocess.run(("gh", "api", *args), check=True, capture_output=True)
    return result.stdout if binary else json.loads(result.stdout)



def download_artifact(repo, artifact_id, manifest_file="preflight-sha256.json"):
    """Download a named workflow artifact using the GitHub CLI binary-safe path."""
    import tempfile
    with tempfile.TemporaryDirectory() as directory:
        metadata = gh(f"repos/{repo}/actions/artifacts/{artifact_id}")
        run_id = metadata["workflow_run"]["id"]
        subprocess.run(("gh", "run", "download", str(run_id), "--repo", repo,
                        "--name", metadata["name"], "--dir", directory),
                       check=True, capture_output=True)
        manifest_file = os.path.join(directory, manifest_file)
        raw = open(manifest_file, "rb").read(1024 * 1024 + 1)
        if len(raw) > 1024 * 1024:
            raise ValueError("Artifact exceeds 1 MiB")
        return raw



def download_lot_sale_artifact(repo, run_id, name, file_name):
    """Use GitHub CLI to download only the explicitly verified plan artifact."""
    import tempfile
    with tempfile.TemporaryDirectory() as directory:
        subprocess.run(("gh", "run", "download", str(run_id), "--repo", repo,
                        "--name", name, "--dir", directory),
                       check=True, capture_output=True)
        path = Path(directory) / file_name
        raw = path.read_bytes()
        if not raw or len(raw) > 1024 * 1024:
            raise ValueError("Invalid plan artifact size")
        return json.loads(raw)


def lot_sale_authorization(repo, text, main_sha):
    """Read-only check of actual prior plan, before any issue consumption."""
    data = parse_lot_sale(text)
    rid = int(data["plan_run_id"])
    run = gh(f"repos/{repo}/actions/runs/{rid}")
    jobs = gh(f"repos/{repo}/actions/runs/{rid}/jobs").get("jobs", [])
    good_jobs = [j["name"] for j in jobs if j.get("conclusion") == "success"
                 and j.get("name") in ("plan", "readonly")]
    artifacts = gh(f"repos/{repo}/actions/runs/{rid}/artifacts").get("artifacts", [])
    candidates = [a for a in artifacts if not a.get("expired") and a.get("name") in
                  (f"lot-sale-three-preflight-{rid}", f"lot-sale-auto-readonly-{rid}")]
    if len(candidates) != 1:
        raise ValueError("Expected one unexpired exact read-only plan artifact")
    name = candidates[0]["name"]
    manifest = download_lot_sale_artifact(repo, rid, name, "lot-sale-three-preflight.json")
    verify_lot_sale_plan(data, run, manifest, main_sha,
                         job_names=good_jobs, artifact_name=name)
    return data


def has_closed_event(repo, issue_number):
    """An issue that has EVER been closed cannot authorize another deploy."""
    result = subprocess.run(("gh", "api", f"repos/{repo}/issues/{issue_number}/events",
                             "--paginate", "--jq", ".[].event"),
                            check=True, capture_output=True, text=True)
    return "closed" in result.stdout.splitlines()


def reject_prior_approved_release(repo, issue_number, workflow_name, release_sha):
    """Fail closed if an older owner authorization issue consumed this release.

    Scan bounded GitHub issue/comment history; API errors or excess history
    are blockers, never permission to dispatch. No GitHub writes.
    """
    if not re.fullmatch(r"[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+", repo):
        raise ValueError("Invalid repository")
    if not re.fullmatch(r"[a-zA-Z0-9_-]+\.yml", workflow_name):
        raise ValueError("Invalid workflow")
    if not re.fullmatch(r"[0-9a-f]{40}", release_sha):
        raise ValueError("Invalid release SHA")
    response = gh(f"repos/{repo}/issues?state=all&labels=approved-deploy-request&per_page=100")
    if not isinstance(response, list) or len(response) >= 100:
        raise ValueError("Issue history incomplete; manual review required")
    for other in response:
        if not isinstance(other, dict) or type(other.get("number")) is not int:
            raise ValueError("Invalid prior issue history")
        if other["number"] == int(issue_number) or "pull_request" in other:
            continue
        # Reopened issues may still contain a consumed release.
        if not has_closed_event(repo, other["number"]):
            continue
        comments = gh(f"repos/{repo}/issues/{other['number']}/comments?per_page=100")
        if not isinstance(comments, list) or len(comments) >= 100:
            raise ValueError("Approval comment history incomplete")
        for comment in comments:
            if not isinstance(comment, dict) or not isinstance(comment.get("body"), str):
                raise ValueError("Invalid approval history")
            lines = comment["body"].replace("\r", "").strip().split("\n")
            if not lines:
                continue
            if lines[0] == "DEPLOY VERIFIED RELEASE":
                fields = dict(line.split("=", 1) for line in lines[1:]
                              if line.count("=") == 1)
                candidate = fields.get("workflow"), fields.get("release_sha")
            elif lines[0] == LOT_SALE_PREFIX:
                fields = dict(line.split("=", 1) for line in lines[1:]
                              if line.count("=") == 1)
                candidate = LOT_SALE_WORKFLOW, fields.get("release_sha")
            elif lines[0] == PREFIX:
                fields = dict(line.split("=", 1) for line in lines[1:]
                              if line.count("=") == 1)
                candidate = WORKFLOW, fields.get("release_sha")
            else:
                continue
            if candidate == (workflow_name, release_sha):
                raise ValueError("Release already authorized in a prior consumed issue")


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
    is_lot_sale = text.replace("\r", "").strip().split("\n")[0] == LOT_SALE_PREFIX
    if is_lot_sale:
        data = lot_sale_authorization(repo, text, main_sha)
        registration = None
    else:
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
        manifest = json.loads(download_artifact(repo, matches[0]["id"], config.get("manifest_file", "preflight-sha256.json") if registration is not None else "preflight-sha256.json"))
        data = (validate_registered(text, normalize_registered_manifest(manifest, run, workflow, config), run, main_sha, workflow, config)
                if registration is not None else validate(text, manifest, run, main_sha))
    # Cross-issue replay protection is read-only and must apply to dry runs too.
    # Never report a green dry run for a release the live controller would reject.
    target_workflow_identity = (LOT_SALE_WORKFLOW if is_lot_sale else
                                workflow if registration is not None else WORKFLOW)
    reject_prior_approved_release(repo, issue_number, target_workflow_identity, data["release_sha"])
    # Dry run checks the same read-only release evidence without consuming approval.
    if os.environ.get("CONTROLLER_DRY_RUN", "false") == "true":
        print("DRY RUN PASS: authorization, artifact and replay history verified; no issue write or dispatch")
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
    target_workflow = (LOT_SALE_WORKFLOW if is_lot_sale else
                       workflow if registration is not None else WORKFLOW)
    confirmation = (LOT_SALE_CONFIRMATION if is_lot_sale else
                    config["confirmation"] if registration is not None else PREFIX)
    hash_keys = (() if is_lot_sale else
                 config["hash_inputs"] if registration is not None else FIELDS[2:])
    command = ("gh", "workflow", "run", target_workflow, "--repo", repo, "--ref", "main",
               "-f", "mode=deploy", "-f", f"release_sha={data['release_sha']}",
               "-f", f"confirmation={confirmation}")
    command += tuple(item for key in hash_keys for item in ("-f", f"{key}={data[key]}"))
    if is_lot_sale:
        command += ("-f", f"plan_run_id={data['plan_run_id']}",
                    "-f", f"schema_ready={data['schema_ready']}")
    if registration is not None and config.get("manual_schema_gate") is True:
        command += ("-f", f"manual_https_precheck={data['manual_https_precheck']}",
                    "-f", f"migration_confirmed={data['migration_confirmed']}")
    elif registration is not None and config.get("manual_https_gate") is True:
        command += ("-f", f"manual_https_precheck={data['manual_https_precheck']}")
    dispatch_approved(command)
    print("Approved registered deployment dispatched once; approval issue closed.")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("STOP: " + str(exc), file=sys.stderr)
        sys.exit(1)
