#!/usr/bin/env python3
"""Dormant dispatch orchestrator: no live transport, no credentials or activation path.

A future separately reviewed service must provide authenticated evidence and
durable atomic reservation. This module can only simulate calls with test fakes.
"""
from owner_bound_dispatch_review import review

ENABLED = False
DENY = {"status": "DENIED", "dispatch_attempted": False, "retry_allowed": False}


def prepare(ticket, evidence):
    """Offline review; cannot turn checked booleans into production authority."""
    result = review(ticket, evidence)
    if result.get("decision") != "READY_FOR_SEPARATE_OWNER_REVIEW":
        return dict(DENY, reason="Preflight rejected")
    return {**DENY, "status": "REVIEW_REQUIRED",
            "reason": "Authenticated owner, durable nonce and live transport unavailable",
            "release_id": result["release_id"], "main_sha": result["main_sha"]}


def dispatch(*_args, **_kwargs):
    """Deliberately impossible to activate by inputs, environment, or token."""
    return dict(DENY, reason="No approved live dispatch adapter installed")


def verify_receipt(receipt, release_id, main_sha):
    """Read-only receipt classifier; ambiguous writes are never retryable."""
    if type(receipt) is not dict:
        return dict(DENY, status="AMBIGUOUS_STOP_NO_RETRY",
                    reason="Missing or malformed dispatch receipt")
    if receipt.get("release_id") != release_id or receipt.get("main_sha") != main_sha:
        return dict(DENY, status="AMBIGUOUS_STOP_NO_RETRY",
                    reason="Receipt identity mismatch")
    if receipt.get("attempted") is not True:
        return dict(DENY, status="NOT_ATTEMPTED",
                    reason="Explicit proof of no attempt is not a dispatch authorization")
    if receipt.get("confirmed") is True and type(receipt.get("run_id")) is int and receipt["run_id"] > 0:
        return {**DENY, "status": "READBACK_REQUIRED", "run_id": receipt["run_id"],
                "reason": "Run exists only as a claim; independently check GitHub"}
    return dict(DENY, status="AMBIGUOUS_STOP_NO_RETRY",
                reason="An attempted request is irreversible without independent reconciliation")
