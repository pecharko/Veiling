#!/usr/bin/env python3
"""Offline, narrowly scoped development repair proposal (never applies patches).

Only a deterministic fixture is supported. This is a proof of concept, not a
general-purpose coding agent and never writes repository files.
"""
from dataclasses import dataclass

@dataclass(frozen=True)
class Proposal:
    status: str
    reason: str
    old: str = ""
    new: str = ""


def propose_fixture_fix(path: str, source: str, failing_test: str) -> Proposal:
    if path != "tests/fixtures/phase_c_typo.php":
        return Proposal("HUMAN_REVIEW", "Outside the isolated exercise")
    if failing_test != "phase-c-fixture-expected-true":
        return Proposal("HUMAN_REVIEW", "Unrecognized CI failure")
    old = "return flase;"
    if source.count(old) != 1 or len(source) > 1024:
        return Proposal("HUMAN_REVIEW", "Unexpected fixture content")
    if not source.lstrip().startswith("<?php"):
        return Proposal("HUMAN_REVIEW", "Not a PHP fixture")
    return Proposal("PROPOSED_ONLY", "Deterministic typo correction", old, "return false;")


if __name__ == "__main__":
    raise SystemExit("Import from offline tests only; no executable repair action")
