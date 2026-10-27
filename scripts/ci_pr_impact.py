#!/usr/bin/env python3
"""Fail-closed PR impact classifier for the two isolated MySQL/HTTP suites.

This ONLY decides whether known-nonfunctional changes can skip both suites.
Unknown, mixed, shared-code, test-infrastructure or workflow changes MUST run
both suites. The PHP/repository/security checks still run independently.
The script has no GitHub API, SFTP, database, or write side effects.
"""
import sys

MAX_PATHS = 5000
MAX_INPUT = 1024 * 1024

READONLY_CONTROLLER_FILES = {
    "scripts/shared_ovh_controller_adapter.py",
    "scripts/shared_ovh_controller_evidence.py",
    "scripts/ovh_release_evidence_review.py",
    "scripts/ovh_release_evidence_discovery.py",
    "scripts/ovh_deploy_evidence_contract.py",
    "tests/shared_ovh_controller_adapter_test.py",
    "tests/shared_ovh_controller_evidence_test.py",
    "tests/ovh_release_evidence_review_test.py",
    "tests/ovh_release_evidence_discovery_test.py",
    "tests/ovh_deploy_evidence_contract_test.py",
}
DOC_FILES = {"README.md", "LICENSE", "LICENSE.md", "CHANGELOG.md"}
PLAIN_METADATA = ("docs/", "release-requests/", "releases/")


def nonfunctional(path):
    if not isinstance(path, str) or not path:
        return False
    if path in READONLY_CONTROLLER_FILES or path in DOC_FILES:
        return True
    if path.startswith("docs/"):
        return path.endswith((".md", ".txt"))
    if path.startswith(("release-requests/", "releases/")):
        return path.endswith(".json")
    return False


def classify(paths):
    """Return (kavel, snapshot) boolean pair; unknown means run both."""
    if not isinstance(paths, list) or not 1 <= len(paths) <= MAX_PATHS:
        raise ValueError("Missing or excessive Git diff paths")
    seen = set()
    for path in paths:
        if not isinstance(path, str) or path in seen or len(path) > 4096:
            raise ValueError("Invalid or duplicated Git diff path")
        seen.add(path)
        if (path.startswith("/") or "\\" in path
                or any(not part or part in (".", "..") or part.startswith(".")
                       for part in path.split("/"))
                or any(ord(c) < 32 or ord(c) == 127 for c in path)):
            # Dotted paths such as .github MUST be treated as high-impact
            # rather than treated as a missing entry.
            if path.startswith(".github/") and all(
                part not in (".", "..", "") for part in path.split("/")
            ):
                return True, True
            raise ValueError("Unsafe Git diff path")
    run_both = any(not nonfunctional(path) for path in paths)
    return run_both, run_both


def parse_git_diff(data):
    if not isinstance(data, bytes) or not 0 < len(data) <= MAX_INPUT:
        raise ValueError("Empty or oversized NUL-delimited Git diff")
    if not data.endswith(b"\0"):
        raise ValueError("Git diff must be NUL-delimited")
    names = data[:-1].split(b"\0")
    try:
        return [name.decode("utf-8", "strict") for name in names]
    except UnicodeDecodeError as exc:
        raise ValueError("Invalid UTF-8 Git filename") from exc


def main():
    paths = parse_git_diff(sys.stdin.buffer.read(MAX_INPUT + 1))
    kavel, snapshot = classify(paths)
    print("kavel=" + str(kavel).lower())
    print("snapshot=" + str(snapshot).lower())
    print(
        "CI impact: {} file(s); kavel={}, snapshot={}".format(
            len(paths), kavel, snapshot
        ),
        file=sys.stderr,
    )


if __name__ == "__main__":
    main()
