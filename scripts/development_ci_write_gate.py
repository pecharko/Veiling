#!/usr/bin/env python3
"""Offline authorization contract for a future, separately approved CI repair writer.

No GitHub mutation, token, API request, dispatch, or repository write occurs here.
A repair proposal is NOT authorization to apply a repair.
"""
import re

_ALLOWED = re.compile(r"^tests/[A-Za-z0-9_-]+_test\.php$")
_FORBIDDEN = ("private/", "database/", ".github/", "scripts/", "releases/",
              "release-requests/", "catalogus-public/", "evidence-inputs/")


def assess_repair_write(request):
    """Return fail-closed evidence; does not perform a write."""
    refusal = {"decision": "DENY", "can_write": False, "can_merge": False,
               "can_dispatch": False, "production_authorized": False}
    if not isinstance(request, dict) or set(request) != {
        "pr", "expected_head_sha", "actual_head_sha", "branch", "path",
        "base_blob_sha", "replacement", "attempts", "author_approved"}:
        return dict(refusal, reason="Invalid request shape")
    pr = request["pr"]
    if not isinstance(pr, int) or isinstance(pr, bool) or pr < 1:
        return dict(refusal, reason="Invalid PR")
    expected = request["expected_head_sha"]
    if not isinstance(expected, str) or not re.fullmatch(r"[0-9a-f]{40}", expected):
        return dict(refusal, reason="Invalid immutable head")
    if request["actual_head_sha"] != expected:
        return dict(refusal, reason="Stale PR head")
    branch = request["branch"]
    if not isinstance(branch, str) or not re.fullmatch(r"test/phase-c-[a-z0-9-]{1,40}", branch):
        return dict(refusal, reason="Outside isolated test branch")
    path = request["path"]
    if not isinstance(path, str) or path.startswith(_FORBIDDEN) or not _ALLOWED.fullmatch(path):
        return dict(refusal, reason="Protected or unsupported path")
    sha = request["base_blob_sha"]
    if not isinstance(sha, str) or not re.fullmatch(r"[0-9a-f]{40}", sha):
        return dict(refusal, reason="Missing blob compare-and-swap")
    replacement = request["replacement"]
    if not isinstance(replacement, str) or len(replacement.encode("utf-8")) > 2048 or not replacement.startswith("<?php"):
        return dict(refusal, reason="Replacement invalid")
    if request["attempts"] != 0 or not isinstance(request["attempts"], int):
        return dict(refusal, reason="One-shot only")
    if request["author_approved"] is not True:
        return dict(refusal, reason="Separate explicit owner authorization required")
    return {"decision": "ELIGIBLE_FOR_SEPARATE_WRITE_APPROVAL", "can_write": False,
            "can_merge": False, "can_dispatch": False, "production_authorized": False,
            "reason": "Policy contract only; no executable writer exists"}


if __name__ == "__main__":
    raise SystemExit("Offline policy only; no write entrypoint")
