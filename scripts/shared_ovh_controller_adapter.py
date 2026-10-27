#!/usr/bin/env python3
"""Pure, offline contract for a future owner-gated shared OVH release adapter.

NOT wired into verified_deploy_controller.py or any GitHub Actions dispatch.
All metadata and artifact bytes must later be retrieved from trusted GitHub
endpoints at immutable revisions. No network, subprocess, SFTP, or writes here.
"""
from datetime import datetime, timezone
import hashlib
import json
import re

REPO = "pecharko/Veiling"
WORKFLOW = "shared-ovh-release.yml"
PREFLIGHT_WORKFLOW = re.compile(
    r"^(?:pecharko/Veiling/)?\.github/workflows/shared-ovh-auto-manifest\.yml"
    r"(?:@(?:refs/heads/)?main)?$"
)
PREFIX = "DEPLOY SHARED OVH MANIFEST WITH ROLLBACK"
RELEASE_ID = re.compile(r"^[a-z0-9][a-z0-9-]{2,60}$")
SHA = re.compile(r"^[a-f0-9]{40}$")
HASH = re.compile(r"^[a-f0-9]{64}$")
SEGMENT = re.compile(r"^[A-Za-z0-9_.-]+$")
MAX_FILES = 25
MAX_JSON_BYTES = 256 * 1024
MAX_COMMENT_BYTES = 16 * 1024
MAX_AGE_SECONDS = 24 * 3600
SUFFIXES = (".php", ".css", ".js", ".svg", ".webp", ".png",
            ".jpg", ".jpeg", ".html")
PROTECTED = (".github/", "scripts/", "tests/", "private/.env",
             "private/config", "private/snapshots",
             "private/visitor_stats_daily_cron.php")


def _valid_path(path):
    if not isinstance(path, str) or not path.startswith(("catalogus-public/", "private/")):
        return False
    if not path.endswith(SUFFIXES) or any(path.startswith(p) for p in PROTECTED):
        return False
    parts = path.split("/")
    return all(bool(SEGMENT.fullmatch(p)) and p not in (".", "..")
               and not p.startswith(".") for p in parts)


def _json_bytes(raw):
    if not isinstance(raw, bytes) or not 0 < len(raw) <= MAX_JSON_BYTES:
        raise ValueError("Missing or oversized immutable JSON bytes")

    def unique_keys(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError("Duplicate JSON property")
            result[key] = value
        return result

    return json.loads(raw.decode("utf-8"), object_pairs_hook=unique_keys)


def _datetime(value):
    if not isinstance(value, str) or not value:
        raise ValueError("Missing GitHub timestamp")
    result = datetime.fromisoformat(value.replace("Z", "+00:00"))
    if result.tzinfo is None:
        raise ValueError("Naive GitHub timestamp")
    return result.astimezone(timezone.utc)


def _require_fresh(past, now, what):
    age = (now - past).total_seconds()
    if not 0 <= age <= MAX_AGE_SECONDS:
        raise ValueError(what + " is future-dated or older than 24 hours")


def parse_approval(text):
    """Reject missing/extra lines, unknown paths and any implicit wildcard."""
    if (not isinstance(text, str) or len(text.encode("utf-8")) > MAX_COMMENT_BYTES
            or "\r" in text):
        raise ValueError("Unsafe authorization text")
    lines = text.strip().split("\n")
    if len(lines) < 7 or lines[0] != PREFIX:
        raise ValueError("Missing exact shared OVH approval prefix")
    fields = ("release_id", "release_sha", "preflight_run_id",
              "preflight_sha", "manifest_sha256")
    values = {}
    for line, field in zip(lines[1:6], fields):
        if not line.startswith(field + "="):
            raise ValueError("Missing or reordered approval field " + field)
        values[field] = line[len(field) + 1:]
    if (not RELEASE_ID.fullmatch(values["release_id"])
            or not SHA.fullmatch(values["release_sha"])
            or not SHA.fullmatch(values["preflight_sha"])
            or not HASH.fullmatch(values["manifest_sha256"])
            or not re.fullmatch(r"[1-9][0-9]*", values["preflight_run_id"])):
        raise ValueError("Malformed release identity or digest")
    rows = []
    for line in lines[6:]:
        if not line.startswith("file="):
            raise ValueError("Unexpected approval field")
        parts = line[5:].split("|")
        if len(parts) != 3:
            raise ValueError("Invalid approved file triple")
        path, source_sha, remote_sha = parts
        if (not _valid_path(path) or not HASH.fullmatch(source_sha)
                or remote_sha != "ABSENT" and not HASH.fullmatch(remote_sha)):
            raise ValueError("Invalid approved path or hash")
        rows.append({"path": path, "source_sha256": source_sha,
                     "remote_sha256": remote_sha})
    if not 1 <= len(rows) <= MAX_FILES or len({x["path"] for x in rows}) != len(rows):
        raise ValueError("Missing, duplicate or excessive approval paths")
    values["files"] = rows
    values["preflight_run_id"] = int(values["preflight_run_id"])
    return values


def verify_owner_event(approval, issue, comment, previously_closed, now):
    """Pure verification of expected trusted GitHub issue_comment data."""
    owner = REPO.split("/")[0]
    release_id = approval["release_id"]
    if type(previously_closed) is not bool or previously_closed:
        raise ValueError("Approval issue was already consumed or unknown")
    if (not isinstance(issue, dict) or not isinstance(comment, dict)
            or issue.get("state") != "open"
            or issue.get("pull_request") is not None
            or issue.get("title") != "DEPLOY-AUTH: shared-ovh-release/" + release_id
            or issue.get("user", {}).get("login") != owner
            or comment.get("user", {}).get("login") != owner):
        raise ValueError("Not an owner-created/owner-commented approval issue")
    issue_id = issue.get("number")
    if type(issue_id) is not int or issue_id <= 0:
        raise ValueError("Invalid GitHub approval issue ID")
    if comment.get("issue_url") != "https://api.github.com/repos/" + REPO + "/issues/" + str(issue_id):
        raise ValueError("Approval comment belongs to a different issue")
    labels = issue.get("labels")
    if not isinstance(labels, list) or "approved-deploy-request" not in {
        x.get("name") for x in labels if isinstance(x, dict)
    }:
        raise ValueError("Missing reviewed deployment approval label")
    if comment.get("body") != approval["text"]:
        raise ValueError("GitHub approval comment does not match parsed text")
    created = _datetime(comment.get("created_at"))
    if comment.get("updated_at") != comment.get("created_at"):
        raise ValueError("Edited approvals are not allowed")
    issue_created = _datetime(issue.get("created_at"))
    if issue_created > created:
        raise ValueError("Approval issue created after comment")
    _require_fresh(created, now, "Owner approval")
    return created


def verify_preflight(approval, run, artifact_inventory, now, comment_created):
    """Bind the approved preflight to the latest immutable GitHub run evidence."""
    if (not isinstance(run, dict)
            or type(run.get("id")) is not int
            or run["id"] != approval["preflight_run_id"]
            or run.get("head_sha") != approval["preflight_sha"]
            or run.get("head_branch") != "main"
            or run.get("event") not in ("push", "workflow_dispatch")
            or run.get("status") != "completed"
            or run.get("conclusion") != "success"
            or not isinstance(run.get("path"), str)
            or not PREFLIGHT_WORKFLOW.fullmatch(run["path"])):
        raise ValueError("Untrusted or unsuccessful read-only preflight")
    started = _datetime(run.get("created_at"))
    _require_fresh(started, now, "Read-only preflight")
    if started > comment_created:
        raise ValueError("Approval predates its read-only preflight")
    expected = "readonly-release-" + str(run["id"])
    if not isinstance(artifact_inventory, list):
        raise ValueError("Missing GitHub preflight artifact inventory")
    matches = [a for a in artifact_inventory
               if isinstance(a, dict) and a.get("name") == expected]
    if len(matches) != 1:
        raise ValueError("Missing or duplicate read-only preflight artifact")
    artifact = matches[0]
    if (artifact.get("expired") is not False
            or type(artifact.get("id")) is not int or artifact["id"] <= 0
            or "workflow_run" in artifact and
            (not isinstance(artifact["workflow_run"], dict)
             or artifact["workflow_run"].get("id") != run["id"])):
        raise ValueError("Expired or unrelated preflight artifact")


def _validate_manifest(manifest, approved):
    if (not isinstance(manifest, dict) or set(manifest) != {"schema", "files"}
            or type(manifest["schema"]) is not int or manifest["schema"] != 1
            or not isinstance(manifest["files"], list)
            or not 1 <= len(manifest["files"]) <= MAX_FILES):
        raise ValueError("Invalid immutable release manifest")
    seen = set()
    for entry in manifest["files"]:
        if (not isinstance(entry, dict)
                or set(entry) != {"path", "source_sha256", "remote_sha256"}
                or not _valid_path(entry["path"])
                or not isinstance(entry["source_sha256"], str)
                or not HASH.fullmatch(entry["source_sha256"])
                or not isinstance(entry["remote_sha256"], str)
                or entry["remote_sha256"] != "ABSENT"
                and not HASH.fullmatch(entry["remote_sha256"])
                or entry["path"] in seen):
            raise ValueError("Unsafe manifest entry")
        seen.add(entry["path"])
    if manifest["files"] != approved["files"]:
        raise ValueError("Approved allowlist, source or remote baselines mismatch")


def _validate_request(raw, paths):
    req = _json_bytes(raw)
    if (not isinstance(req, dict) or set(req) != {"schema", "paths"}
            or type(req["schema"]) is not int or req["schema"] != 1
            or not isinstance(req["paths"], list) or req["paths"] != paths):
        raise ValueError("Reviewed request paths mismatch")


def build_dispatch_command(approval):
    """Return an inert argument tuple; this module NEVER executes it."""
    ident = approval["release_id"]
    sha = approval["release_sha"]
    if not RELEASE_ID.fullmatch(ident) or not SHA.fullmatch(sha):
        raise ValueError("Invalid dispatch command identity")
    return ("gh", "workflow", "run", WORKFLOW,
            "--repo", REPO, "--ref", "main",
            "-f", "release_id=" + ident,
            "-f", "confirmation=DEPLOY-" + ident + "-" + sha)


def review_offline(text, *, issue, comment, previously_closed, run,
                   artifact_inventory, artifact_files, manifest_bytes,
                   request_at_main, request_at_preflight, current_main_sha,
                   source_hashes, now):
    """Offline plan only; all inputs must be independently fetched by a future guard.

    No authorization is consumed and no dispatch is started. The caller must
    perform the existing GitHub owner-event, one-shot issue consumption and
    protected-environment checks before EVER acting on the returned tuple.
    """
    if not isinstance(now, datetime) or now.tzinfo is None:
        raise ValueError("An aware verification time is required")
    now = now.astimezone(timezone.utc)
    approval = parse_approval(text)
    approval["text"] = text
    if current_main_sha != approval["release_sha"]:
        raise ValueError("Main SHA drifted since owner approval")
    comment_created = verify_owner_event(
        approval, issue, comment, previously_closed, now
    )
    verify_preflight(approval, run, artifact_inventory, now, comment_created)
    if (not isinstance(manifest_bytes, bytes)
            or hashlib.sha256(manifest_bytes).hexdigest() != approval["manifest_sha256"]):
        raise ValueError("Immutable manifest digest differs from owner approval")
    manifest = _json_bytes(manifest_bytes)
    _validate_manifest(manifest, approval)
    paths = [row["path"] for row in approval["files"]]
    if (not isinstance(request_at_main, bytes)
            or not isinstance(request_at_preflight, bytes)
            or request_at_main != request_at_preflight):
        raise ValueError("Release request differs between preflight and latest main")
    _validate_request(request_at_main, paths)
    if (not isinstance(source_hashes, dict) or set(source_hashes) != set(paths)
            or any(not isinstance(v, str) or not HASH.fullmatch(v)
                   for v in source_hashes.values())
            or any(source_hashes[row["path"]] != row["source_sha256"]
                   for row in approval["files"])):
        raise ValueError("Current main source files do not match reviewed manifest")
    if not isinstance(artifact_files, dict) or set(artifact_files) != {
        "shared-ovh-readonly-baselines.json",
        "releases/" + approval["release_id"] + ".json"
    }:
        raise ValueError("Missing or unexpected preflight artifact members")
    if any(data != manifest_bytes for data in artifact_files.values()):
        raise ValueError("Artifact bytes do not match immutable approved manifest")
    return {
        "status": "OFFLINE_CONTRACT_VERIFIED",
        "release_id": approval["release_id"],
        "release_sha": approval["release_sha"],
        "preflight_run_id": approval["preflight_run_id"],
        "manifest_sha256": approval["manifest_sha256"],
        "approved_files": paths,
        "dispatch_command": build_dispatch_command(approval),
        "dispatch_started": False,
        "browser_acceptance": "PENDING",
    }
