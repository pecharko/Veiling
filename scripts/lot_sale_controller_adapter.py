"""Strict no-rollback three-file approval validator. No GitHub API or deployment side effects."""
import re
from datetime import datetime, timezone

WORKFLOW = "lot-sale-three-simple-release.yml"
PLAN = ".github/workflows/lot-sale-three-simple-release.yml"
AUTO_PLAN = ".github/workflows/lot-sale-auto-readonly-preflight.yml"
PATHS = ("private/lot_form.php", "private/snapshots.php", "catalogus-public/admin/kavel.php")
PREFIX = "AUTHORIZE LOT SALE THREE WITHOUT ROLLBACK"
RISK = "I ACCEPT NO ROLLBACK AND POSSIBLE DOWNTIME"
FIELDS = ("release_sha", "plan_run_id", "schema_ready", "risk_acceptance")
CONFIRMATION = "DEPLOY LOT SALE THREE NO ROLLBACK"


def parse_approval(text):
    lines = text.replace("\r", "").strip().split("\n")
    if len(lines) != len(FIELDS) + 1 or lines[0] != PREFIX:
        raise ValueError("Invalid explicit no-rollback authorization")
    result = {}
    for key, line in zip(FIELDS, lines[1:]):
        if not line.startswith(key + "="):
            raise ValueError("Missing or out of order approval field: " + key)
        result[key] = line.partition("=")[2]
    if not re.fullmatch(r"[0-9a-f]{40}", result["release_sha"]):
        raise ValueError("Invalid SHA")
    if not re.fullmatch(r"[1-9][0-9]*", result["plan_run_id"]):
        raise ValueError("Invalid plan ID")
    if result["schema_ready"] != "SCHEMA VERIFIED":
        raise ValueError("Independent schema attestation missing")
    if result["risk_acceptance"] != RISK:
        raise ValueError("Explicit no-rollback risk not accepted")
    return result


def verify_plan(data, run, manifest, main_sha, *, job_names=None, artifact_name=None):
    if data["release_sha"] != main_sha or run.get("head_sha") != main_sha:
        raise ValueError("Release not current main")
    if (run.get("id") != int(data["plan_run_id"]) or
            run.get("status") != "completed" or run.get("conclusion") != "success" or
            run.get("head_branch") != "main"):
        raise ValueError("Untrusted prior plan")
    if run.get("path") == PLAN and run.get("event") == "workflow_dispatch":
        expected_job, expected_artifact = "plan", "lot-sale-three-preflight-" + data["plan_run_id"]
    elif run.get("path") == AUTO_PLAN and run.get("event") == "workflow_run":
        expected_job, expected_artifact = "readonly", "lot-sale-auto-readonly-" + data["plan_run_id"]
    else:
        raise ValueError("Unexpected plan workflow or event")
    if artifact_name != expected_artifact or job_names != [expected_job]:
        raise ValueError("Read-only plan job or artifact not verified")
    created = datetime.fromisoformat(run["created_at"].replace("Z", "+00:00"))
    if not 0 <= (datetime.now(timezone.utc) - created).total_seconds() <= 86400:
        raise ValueError("Expired plan")
    if (set(manifest) != {"release_sha", "files"} or manifest["release_sha"] != main_sha
            or set(manifest["files"]) != set(PATHS)):
        raise ValueError("Plan manifest does not match scope")
    for path in PATHS:
        hashes = manifest["files"][path]
        if set(hashes) != {"remote_sha256", "source_sha256"}:
            raise ValueError("Invalid manifest hashes")
        if any(not isinstance(h, str) or not re.fullmatch(r"[0-9a-f]{64}", h) for h in hashes.values()):
            raise ValueError("Invalid checksum")
    return data
