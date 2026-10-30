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
    if type(request["attempts"]) is not int or request["attempts"] != 0:
        return dict(refusal, reason="One-shot only")
    if request["author_approved"] is not True:
        return dict(refusal, reason="Separate explicit owner authorization required")
    return {"decision": "ELIGIBLE_FOR_SEPARATE_WRITE_APPROVAL", "can_write": False,
            "can_merge": False, "can_dispatch": False, "production_authorized": False,
            "reason": "Policy contract only; no executable writer exists"}


if __name__ == "__main__":
    raise SystemExit("Offline policy only; no write entrypoint")


def _git_blob_sha(content):
    """Calculate the standard Git blob OID without invoking Git or writing."""
    import hashlib
    body = content.encode("utf-8")
    return hashlib.sha1(b"blob " + str(len(body)).encode("ascii") + b"\0" + body).hexdigest()


def prepare_repair_review(request, original_source):
    """Build immutable, OFFLINE review evidence for one deterministic test typo.

    Reuses the existing write gate, but NEVER grants authority. The supplied
    source and request are untrusted until independently obtained from GitHub;
    a matching digest is not owner authentication, a consumed nonce, or a
    branch-head CAS. No API, file, Git, credentials or mutation are used.
    """
    import hashlib
    import json

    denied = {
        "decision": "DENY",
        "can_write": False,
        "can_merge": False,
        "can_dispatch": False,
        "production_authorized": False,
        "owner_identity_verified": False,
        "nonce_reserved": False,
        "source_provenance_authenticated": False,
        "reason": "Ineligible or unverifiable offline repair evidence",
    }

    eligibility = assess_repair_write(request)
    if eligibility.get("decision") != "ELIGIBLE_FOR_SEPARATE_WRITE_APPROVAL":
        return denied
    if (type(original_source) is not str or
            len(original_source.encode("utf-8")) > 2048 or
            not original_source.startswith("<?php") or
            "\x00" in original_source):
        return denied

    # This exact, well-understood correction is the only supported offline
    # proposal. No generic text edits or source executing are permitted.
    old, new = "return flase;", "return false;"
    if original_source.count(old) != 1:
        return denied
    if _git_blob_sha(original_source) != request["base_blob_sha"]:
        return denied
    expected = original_source.replace(old, new, 1)
    if request["replacement"] != expected:
        return denied

    candidate = expected.encode("utf-8")
    base = {
        "repository": "pecharko/Veiling",
        "pr": request["pr"],
        "branch": request["branch"],
        "head_sha": request["expected_head_sha"],
        "path": request["path"],
        "original_blob_sha": request["base_blob_sha"],
        "candidate_blob_sha": _git_blob_sha(expected),
        "candidate_sha256": hashlib.sha256(candidate).hexdigest(),
        "change": "exactly-one:return flase;->return false;",
    }
    canonical = json.dumps(base, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return {
        **denied,
        **base,
        "decision": "PROPOSED_ONLY",
        "reason": "Deterministic candidate content verified offline; new authenticated owner approval and trusted writer still required",
        "review_sha256": hashlib.sha256(canonical).hexdigest(),
    }
