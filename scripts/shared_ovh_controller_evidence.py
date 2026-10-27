#!/usr/bin/env python3
"""Read-only GitHub evidence reader for the inert shared OVH controller adapter.

Only retrieves GitHub REST metadata, immutable Git contents and the existing
read-only Actions artifact. NEVER comments, closes issues or dispatches a run.
This module is NOT connected to the live GitHub deployment controller.
"""

import base64
from datetime import datetime, timezone
import hashlib
import io
import json
import re
import stat
from urllib.parse import quote
from urllib.request import Request, build_opener
import zipfile

from ovh_release_evidence_discovery import ArtifactRedirect, get as github_get
from shared_ovh_controller_adapter import (
    HASH, RELEASE_ID, SHA, MAX_JSON_BYTES, _valid_path,
    parse_approval, review_offline,
)

BASE = "https://api.github.com/repos/pecharko/Veiling"
MAX_CONTENT = 8 * 1024 * 1024
MAX_ARCHIVE = 2 * 1024 * 1024
MAX_ENTRIES = 100
JSON_PATH = re.compile(r"^(?:releases|release-requests)/[a-z0-9][a-z0-9-]{2,60}\.json$")


def _positive(value, label):
    if type(value) is not int or value <= 0:
        raise ValueError("Invalid " + label)
    return value


def _api_json(token, path):
    """The URL host/repository are immutable; only validated paths are appended."""
    if not isinstance(token, str) or not token:
        raise ValueError("Missing read-only GitHub API token")
    if not isinstance(path, str) or not path.startswith("/") or "//" in path:
        raise ValueError("Invalid GitHub API path")
    data = github_get(BASE + path, token)
    if len(data) > MAX_ARCHIVE:
        raise ValueError("Oversized GitHub API JSON response")
    return json.loads(data)


def _list(token, path, key=None):
    obj = _api_json(token, path)
    values = obj if key is None else obj.get(key) if isinstance(obj, dict) else None
    if not isinstance(values, list) or len(values) >= MAX_ENTRIES:
        raise ValueError("Missing or unbounded GitHub REST page")
    if key is not None and obj.get("total_count", len(values)) > MAX_ENTRIES - 1:
        raise ValueError("Unpaginated GitHub REST inventory")
    return values


def _bounded_blob_json(token, blob_sha, expected_size):
    """GET only the exact GitHub git-blob endpoint, bounded to 8 MiB raw.

    GitHub Contents API returns encoding=none for larger files. Request the
    immutable content-addressed blob via GitHub's JSON Git Blobs API. The API
    token may NEVER be forwarded on an HTTP redirect from this endpoint.
    """
    if (not isinstance(token, str) or not token
            or not isinstance(blob_sha, str) or not SHA.fullmatch(blob_sha)
            or type(expected_size) is not int
            or not 0 < expected_size <= MAX_CONTENT):
        raise ValueError("Unsafe immutable Git blob request")
    url = BASE + "/git/blobs/" + blob_sha
    max_json_bytes = ((expected_size + 2) // 3) * 4 + 64 * 1024
    request = Request(url, headers={
        "Authorization": "Bearer " + token,
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
    }, method="GET")
    # This handler disallows all redirects except the special unrelated
    # artifact endpoint, where Authorization is explicitly stripped.
    opener = build_opener(ArtifactRedirect())
    with opener.open(request, timeout=20) as response:
        if response.geturl() != url or response.status != 200:
            raise ValueError("Redirected or unsuccessful immutable Git blob GET")
        payload = response.read(max_json_bytes + 1)
    if len(payload) > max_json_bytes:
        raise ValueError("Oversized immutable Git blob JSON")
    return json.loads(payload)


def _git_file(token, path, sha, *, limit=MAX_CONTENT):
    if not SHA.fullmatch(sha):
        raise ValueError("Unsafe immutable Git commit")
    if not (JSON_PATH.fullmatch(path) or _valid_path(path)):
        raise ValueError("Disallowed immutable Git contents path")
    uri = "/contents/" + quote(path, safe="/") + "?ref=" + sha
    item = _api_json(token, uri)
    if (not isinstance(item, dict) or item.get("type") != "file"
            or type(item.get("size")) is not int
            or not 0 < item["size"] <= limit):
        raise ValueError("Invalid immutable Git contents metadata")

    encoding = item.get("encoding")
    if encoding == "base64":
        if not isinstance(item.get("content"), str):
            raise ValueError("Invalid immutable Git Base64 contents")
        raw = base64.b64decode(item["content"].replace("\n", ""), validate=True)
    elif encoding == "none":
        # GitHub REST Contents API returns only metadata for files over 1 MB.
        # Bind the Git Blob response to the exact content-addressed blob SHA
        # supplied by the Contents endpoint at the immutable commit.
        blob_sha = item.get("sha")
        if (not isinstance(blob_sha, str) or not SHA.fullmatch(blob_sha)
                or item.get("content") not in ("", None)):
            raise ValueError("Invalid immutable Git blob metadata")
        blob = _bounded_blob_json(token, blob_sha, item["size"])
        if (not isinstance(blob, dict) or blob.get("sha") != blob_sha
                or blob.get("size") != item["size"]
                or blob.get("encoding") != "base64"
                or not isinstance(blob.get("content"), str)):
            raise ValueError("Immutable Git Blob response disagrees with Contents")
        raw = base64.b64decode(blob["content"].replace("\n", ""), validate=True)
        # The Git object ID is a SHA-1 over the typed Git object, not over the
        # plain file data; source SHA-256 is separately checked by the adapter.
        typed = b"blob " + str(len(raw)).encode("ascii") + b"\0" + raw
        if hashlib.sha1(typed).hexdigest() != blob_sha:
            raise ValueError("Immutable Git Blob object hash mismatch")
    else:
        raise ValueError("Unsupported immutable Git contents encoding")
    if len(raw) != item["size"] or len(raw) > limit:
        raise ValueError("Immutable Git file size mismatch")
    return raw


def _artifact_files(token, artifact_id, release_id):
    _positive(artifact_id, "read-only GitHub artifact ID")
    if not RELEASE_ID.fullmatch(release_id):
        raise ValueError("Invalid reviewed release ID")
    zipped = github_get(BASE + "/actions/artifacts/" + str(artifact_id) + "/zip", token)
    if not isinstance(zipped, bytes) or len(zipped) > MAX_ARCHIVE:
        raise ValueError("Invalid or oversized GitHub artifact ZIP")
    expected = {"shared-ovh-readonly-baselines.json",
                "releases/" + release_id + ".json"}
    try:
        with zipfile.ZipFile(io.BytesIO(zipped)) as zf:
            members = zf.infolist()
            if len(members) != 2 or {m.filename for m in members} != expected:
                raise ValueError("Missing, duplicate or extraneous read-only evidence ZIP members")
            for m in members:
                mode = m.external_attr >> 16
                if (m.is_dir() or stat.S_ISLNK(mode) or m.file_size <= 0
                        or m.file_size > MAX_JSON_BYTES
                        or m.compress_size > MAX_ARCHIVE):
                    raise ValueError("Unsafe read-only evidence ZIP member")
            files = {m.filename: zf.read(m) for m in members}
    except zipfile.BadZipFile as exc:
        raise ValueError("Invalid GitHub Actions read-only artifact") from exc
    if any(len(contents) > MAX_JSON_BYTES for contents in files.values()):
        raise ValueError("Expanded evidence exceeds allowed size")
    return files


def _ever_closed(token, issue_number):
    events = _list(token,
                   "/issues/" + str(issue_number) + "/events?per_page=100")
    if any(not isinstance(event, dict) or not isinstance(event.get("event"), str)
           for event in events):
        raise ValueError("Unexpected GitHub issue history record")
    return any(event["event"] == "closed" for event in events)


def collect_review(token, issue_number, comment_id, now=None):
    """Build a verified, INERT review from independent GitHub REST evidence.

    Both IDs must originate from a trusted GitHub issue_comment event in any
    eventual future controller. A chat message is not a GitHub owner event.
    No state is consumed and no network requests outside GitHub's REST API
    and the existing token-stripping artifact redirect are performed.
    """
    issue_number = _positive(issue_number, "approval issue number")
    comment_id = _positive(comment_id, "approval comment ID")
    if now is None:
        now = datetime.now(timezone.utc)
    if not isinstance(now, datetime) or now.tzinfo is None:
        raise ValueError("Require timezone-aware read-only review time")
    main = _api_json(token, "/branches/main")
    current_sha = (main.get("commit") or {}).get("sha") if isinstance(main, dict) else None
    if not isinstance(current_sha, str) or not SHA.fullmatch(current_sha):
        raise ValueError("Missing current immutable main SHA")
    issue = _api_json(token, "/issues/" + str(issue_number))
    comment = _api_json(token, "/issues/comments/" + str(comment_id))
    if not isinstance(comment, dict) or comment.get("id") != comment_id:
        raise ValueError("GitHub comment ID mismatch")
    approval = parse_approval(comment.get("body"))
    release_id = approval["release_id"]
    run_id = approval["preflight_run_id"]
    run = _api_json(token, "/actions/runs/" + str(run_id))
    artifact_inventory = _list(
        token, "/actions/runs/" + str(run_id) + "/artifacts?per_page=100",
        "artifacts",
    )
    expected_name = "readonly-release-" + str(run_id)
    candidates = [
        x for x in artifact_inventory
        if isinstance(x, dict) and x.get("name") == expected_name
        and x.get("expired") is False
    ]
    if len(candidates) != 1:
        raise ValueError("Missing unexpired exact preflight artifact")
    artifact = candidates[0]
    artifact_files = _artifact_files(token, artifact.get("id"), release_id)
    manifest = _git_file(
        token, "releases/" + release_id + ".json", current_sha,
        limit=MAX_JSON_BYTES,
    )
    request_path = "release-requests/" + release_id + ".json"
    request_main = _git_file(token, request_path, current_sha,
                             limit=MAX_JSON_BYTES)
    request_preflight = _git_file(token, request_path, approval["preflight_sha"],
                                  limit=MAX_JSON_BYTES)
    source_hashes = {
        row["path"]: hashlib.sha256(
            _git_file(token, row["path"], current_sha)
        ).hexdigest()
        for row in approval["files"]
    }
    # A failed preflight can only qualify when *every* independent step
    # proves success except the bot PR-creation step; never trust a red run.
    jobs = None
    if isinstance(run, dict) and run.get("conclusion") == "failure":
        jobs = _list(token,
                     "/actions/runs/" + str(run_id) + "/jobs?per_page=100",
                     "jobs")
    return review_offline(
        comment["body"], issue=issue, comment=comment,
        previously_closed=_ever_closed(token, issue_number),
        run=run, artifact_inventory=artifact_inventory,
        artifact_files=artifact_files, manifest_bytes=manifest,
        request_at_main=request_main, request_at_preflight=request_preflight,
        current_main_sha=current_sha, source_hashes=source_hashes,
        now=now, preflight_jobs=jobs,
    )
