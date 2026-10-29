"""Offline-only Phase C one-shot state-machine simulation.

Pure Python: no GitHub client, subprocess, network or persistent state.
Never grants real write authorization. No production connection.
"""
from dataclasses import dataclass, replace
from typing import Optional

BRANCH = "test/phase-c-writer-fixture-20261029"
PATH = "tests/phase_c_sandbox_test.php"
HEAD = "2595a63c6cd575dbbb7f29f293ccccaccc140553"
BLOB = "a16bb38acbde1422389e1fcb4835c20688f594b3"
ISSUE = 427
NONCE = "refs/tags/phase-c-once-issue-427"


@dataclass(frozen=True)
class Fixture:
    branch: str = BRANCH
    head: str = HEAD
    blob: str = BLOB
    issue: int = ISSUE
    owner_verified: bool = True
    rules_verified: bool = True
    nonce_exists: bool = False
    nonce_create_result: str = "created"
    cas_result: str = "updated"
    readback_matches: bool = True
    attempts: int = 0
    old_content: str = "<?php\nreturn flase;\n"
    path: str = PATH


def simulate_once(f: Fixture) -> dict:
    """Model operations as names, not executable GitHub API requests."""
    ops = []
    deny = lambda reason: {"state": "DENY", "reason": reason, "operations": ops,
                           "write_allowed": False, "retry_allowed": False}
    if (f.branch != BRANCH or f.head != HEAD or f.blob != BLOB or
            f.issue != ISSUE or f.path != PATH or
            not f.owner_verified or not f.rules_verified or f.attempts != 0 or
            f.old_content.count("return flase;") != 1 or
            not f.old_content.startswith("<?php")):
        return deny("Precondition mismatch")
    if f.nonce_exists:
        return deny("Nonce already present")
    # An untrusted 404 is not sufficient for a real writer; this is a simulation.
    ops.append("SIMULATED_CREATE_NONCE")
    if f.nonce_create_result != "created":
        return deny("Uncertain or failed reservation: terminal")
    ops.append("SIMULATED_NON_FORCE_CAS")
    if f.cas_result != "updated":
        return deny("Concurrent change or uncertain branch write: terminal")
    ops.append("SIMULATED_READBACK")
    if not f.readback_matches:
        return deny("Readback mismatch: terminal")
    return {"state": "SIMULATED_SUCCESS_NOT_AUTHORIZATION",
            "operations": ops, "write_allowed": False, "retry_allowed": False}
