"""Phase C writer transaction kernel — deliberately DISABLED.

No HTTP, subprocess, token, filesystem, GitHub imports, environment switches
or production paths. The ONLY entrypoint denies before calling a transport.
A future reviewed change must implement independent identity verification,
a trusted atomic ref transport and explicit activation separately.
"""
from dataclasses import dataclass

REPOSITORY = "pecharko/Veiling"
BRANCH = "test/phase-c-writer-fixture-20261029"
HEAD = "2595a63c6cd575dbbb7f29f293ccccaccc140553"
BLOB = "a16bb38acbde1422389e1fcb4835c20688f594b3"
PATH = "tests/phase_c_sandbox_test.php"
NONCE = "refs/tags/phase-c-once-issue-427"
ISSUE = 427

# Immutable deny switch: no flags, parameters, env vars, or workflow inputs
# can turn this into an operational writer.
WRITER_ENABLED = False


@dataclass(frozen=True)
class Transaction:
    repository: str = REPOSITORY
    branch: str = BRANCH
    head: str = HEAD
    blob: str = BLOB
    path: str = PATH
    nonce: str = NONCE
    issue: int = ISSUE


def prepare_transaction(transaction: Transaction) -> dict:
    """Validate a pinned request for offline inspection, never authorize it."""
    expected = Transaction()
    if type(transaction) is not Transaction or transaction != expected:
        return {"decision": "DENY", "reason": "Immutable scope mismatch",
                "write_allowed": False}
    return {"decision": "PREPARED_INACTIVE", "write_allowed": False,
            "nonce": NONCE, "expected_head": HEAD, "expected_blob": BLOB,
            "file_path": PATH}


def execute_transaction(transport=None, transaction=None, **_ignored) -> dict:
    """Always deny, including when a transport is supplied.

    This entrypoint MUST remain fail-closed. An injected fake with methods
    that would mutate refs must never be called. Authorization is a separate
    future review, not an input switch.
    """
    return {"decision": "DENY", "reason": "Phase C writer not activated",
            "write_allowed": False, "retry_allowed": False,
            "operations": []}
