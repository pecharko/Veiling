#!/usr/bin/env python3
"""Phase D1: deterministic, read-only PHP/CSS fix proposals, never application.

The JSON evidence and source are untrusted. A valid proposal is neither a
trusted GitHub readback nor permission to write, merge or deploy. No imports of
GitHub transports, no subprocesses, no credentials, no filesystem writes.
"""
import difflib
import hashlib
import json
import re
import sys

REPO = "pecharko/Veiling"
HEX40 = re.compile(r"[0-9a-f]{40}\Z")
PHP_TEST = re.compile(r"tests/[A-Za-z0-9_-]+_test\.php\Z")
PHP_PUBLIC = re.compile(r"catalogus-public/[A-Za-z0-9_-]+\.php\Z")
CSS_ASSET = re.compile(r"catalogus-public/assets/[A-Za-z0-9_./-]+\.css\Z")
MAX_SOURCE_BYTES = 65536
MAX_PATCH_BYTES = 8192
REJECT = {
    "decision": "HUMAN_REVIEW", "can_write": False, "can_merge": False,
    "can_dispatch": False, "can_deploy": False, "retry_allowed": False,
    "author_approval_verified": False, "github_origin_verified": False,
}

# Single-line, line-anchored corrections only; no arbitrary replacements.
RULES = (
    ("php-return-false-typo", "php", re.compile(r"(?m)^([ \t]*)return flase;([ \t]*)$"), r"\1return false;\2"),
    ("php-return-true-typo", "php", re.compile(r"(?m)^([ \t]*)return ture;([ \t]*)$"), r"\1return true;\2"),
    ("css-display-typo", "css", re.compile(r"(?m)^([ \t]*)dispaly:([ \t]*)(flex|grid|block|none);([ \t]*)$"), r"\1display:\2\3;\4"),
    ("css-align-items-typo", "css", re.compile(r"(?m)^([ \t]*)algin-items:([ \t]*)(center|flex-start|flex-end|stretch);([ \t]*)$"), r"\1align-items:\2\3;\4"),
)


def git_blob(content):
    body = content.encode("utf-8")
    return hashlib.sha1(b"blob " + str(len(body)).encode("ascii") + b"\0" + body).hexdigest()


def _kind(path):
    if ".." in path.split("/") or "//" in path or path.startswith("/"):
        return None
    if PHP_TEST.fullmatch(path) or PHP_PUBLIC.fullmatch(path):
        return "php"
    if CSS_ASSET.fullmatch(path) and not path.startswith("catalogus-public/assets/admin/"):
        return "css"
    return None


def propose(evidence):
    """Return bounded, audit-friendly evidence; always no-write and fail closed."""
    denied = dict(REJECT, reason="Outside permitted D1 offline proposal contract")
    if type(evidence) is not dict or set(evidence) != {
            "repository", "issue", "head_sha", "path", "base_blob_sha", "source"}:
        return denied
    repo, issue = evidence["repository"], evidence["issue"]
    head, path, base = (evidence["head_sha"], evidence["path"],
                        evidence["base_blob_sha"])
    source = evidence["source"]
    if (repo != REPO or type(issue) is not int or issue <= 0
            or type(head) is not str or not HEX40.fullmatch(head)
            or type(base) is not str or not HEX40.fullmatch(base)
            or type(path) is not str or len(path) > 180 or _kind(path) is None
            or type(source) is not str or "\0" in source or "\r" in source
            or not source.endswith("\n") or len(source) > MAX_SOURCE_BYTES
            or len(source.encode("utf-8", errors="surrogatepass")) > MAX_SOURCE_BYTES):
        return denied
    try:
        if git_blob(source) != base:
            return dict(denied, reason="Source does not match immutable Git blob")
    except UnicodeEncodeError:
        return denied
    kind = _kind(path)
    if kind == "php" and not source.startswith("<?php\n"):
        return denied
    found = []
    for name, language, regex, replacement in RULES:
        if language != kind:
            continue
        matches = list(regex.finditer(source))
        if matches:
            found.extend((name, regex, replacement, m.start()) for m in matches)
    if len(found) != 1:
        return dict(denied, reason="No unique supported correction")
    rule, regex, replacement, _ = found[0]
    updated, count = regex.subn(replacement, source, count=1)
    if count != 1 or updated == source or len(updated.encode("utf-8")) > MAX_SOURCE_BYTES:
        return denied
    patch = "".join(difflib.unified_diff(
        source.splitlines(keepends=True), updated.splitlines(keepends=True),
        fromfile="a/" + path, tofile="b/" + path, n=2,
    ))
    if not patch or len(patch.encode("utf-8")) > MAX_PATCH_BYTES:
        return denied
    immutable = {
        "repository": REPO, "issue": issue, "head_sha": head,
        "path": path, "base_blob_sha": base,
        "candidate_blob_sha": git_blob(updated),
        "candidate_sha256": hashlib.sha256(updated.encode("utf-8")).hexdigest(),
        "rule": rule,
    }
    digest = hashlib.sha256(json.dumps(
        immutable, sort_keys=True, separators=(",", ":"),
    ).encode("utf-8")).hexdigest()
    return {
        **REJECT, **immutable, "decision": "PROPOSED_ONLY",
        "reason": "Offline deterministic proposal; authenticated review and fresh approval required",
        "review_sha256": digest, "patch": patch,
        "changed_files": 1, "new_lines": 1, "removed_lines": 1,
    }


def main():
    try:
        # Limit stdin even when called directly, to avoid unbounded JSON input.
        raw = sys.stdin.buffer.read(2 * MAX_SOURCE_BYTES + 1)
        if len(raw) > 2 * MAX_SOURCE_BYTES:
            raise ValueError("Input exceeds limit")
        report = propose(json.loads(raw.decode("utf-8")))
    except (ValueError, UnicodeError, TypeError, RecursionError):
        report = dict(REJECT, reason="Malformed or oversized input")
    print(json.dumps(report, sort_keys=True, ensure_ascii=True))
    return 0 if report["decision"] == "PROPOSED_ONLY" else 2


if __name__ == "__main__":
    raise SystemExit(main())
