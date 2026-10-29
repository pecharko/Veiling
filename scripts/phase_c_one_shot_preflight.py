"""Offline Phase C one-shot transaction refusal policy.

This does not store or mutate a nonce. A future execution worker must use
a real atomic durable compare-and-swap reservation before any GitHub write.
"""
import re

SHA = re.compile(r"^[0-9a-f]{40}$")
PATH = "tests/phase_c_sandbox_test.php"


def check_one_shot_preflight(authorization, current):
    denied = {"decision": "DENY", "can_write": False, "can_dispatch": False,
              "can_merge": False, "production_authorized": False}
    if not isinstance(authorization, dict) or not isinstance(current, dict):
        return dict(denied, reason="Malformed inputs")
    keys = ("issue_id", "comment_id", "pr_number", "head_sha", "blob_sha",
            "new_sha256", "target_path")
    if any(k not in authorization for k in keys):
        return dict(denied, reason="Incomplete approval")
    if any(type(authorization[k]) is not int or authorization[k] <= 0
           for k in ("issue_id", "comment_id", "pr_number")):
        return dict(denied, reason="Invalid pinned IDs")
    for k in ("head_sha", "blob_sha"):
        if not isinstance(authorization[k], str) or not SHA.fullmatch(authorization[k]):
            return dict(denied, reason="Invalid approved SHA")
        if current.get(k) != authorization[k]:
            return dict(denied, reason="Head or blob drift")
    digest = authorization["new_sha256"]
    if not isinstance(digest, str) or not re.fullmatch(r"[a-f0-9]{64}", digest):
        return dict(denied, reason="Invalid result digest")
    if authorization["target_path"] != PATH or current.get("target_path") != PATH:
        return dict(denied, reason="Wrong target path")
    if current.get("owner_verified") is not True:
        return dict(denied, reason="Owner not verified")
    if current.get("nonce_reserved_atomically") is not True:
        return dict(denied, reason="Atomic reservation not proven")
    if current.get("nonce_replayed") is not False:
        return dict(denied, reason="Replay or ambiguous nonce")
    if current.get("pr_is_open") is not True or current.get("from_fork") is not False:
        return dict(denied, reason="Invalid PR state")
    return {**denied, "decision": "READY_FOR_TRUSTED_WRITER_REVIEW",
            "reason": "Offline state simulation only; real nonce store and CAS not installed"}
