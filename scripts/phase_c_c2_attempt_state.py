"""Offline-only C2 attempt protocol. No GitHub network or writes.

A durable nonce reservation is a prerequisite for any future trusted writer.
Unknown outcomes consume the attempt and must not be retried.
"""
from dataclasses import dataclass, replace

STATES = frozenset(("NEW", "NONCE_RESERVED", "REF_UPDATE_ATTEMPTED",
                    "VERIFIED", "AMBIGUOUS", "FAILED_CLOSED"))
EVENTS = frozenset(("reserve_confirmed", "reserve_failed", "submit_ref_update",
                    "verified_exact", "response_ambiguous", "update_rejected"))


@dataclass(frozen=True)
class Attempt:
    state: str = "NEW"
    nonce_spent: bool = False
    update_attempts: int = 0


def transition(attempt, event):
    """Return immutable local model; deliberately never issues a write."""
    denied = {"decision": "DENY", "write_allowed": False, "retry_allowed": False}
    if type(attempt) is not Attempt or attempt.state not in STATES:
        return dict(denied, reason="Invalid attempt")
    if type(event) is not str or event not in EVENTS:
        return dict(denied, reason="Invalid event")
    if attempt.state == "NEW":
        if event == "reserve_confirmed" and not attempt.nonce_spent and attempt.update_attempts == 0:
            return dict(denied, attempt=replace(attempt, state="NONCE_RESERVED", nonce_spent=True))
        if event == "reserve_failed":
            return dict(denied, attempt=replace(attempt, state="FAILED_CLOSED", nonce_spent=True))
    if attempt.state == "NONCE_RESERVED" and attempt.nonce_spent and attempt.update_attempts == 0:
        if event == "submit_ref_update":
            return dict(denied, attempt=replace(attempt, state="REF_UPDATE_ATTEMPTED", update_attempts=1))
    if attempt.state == "REF_UPDATE_ATTEMPTED" and attempt.nonce_spent and attempt.update_attempts == 1:
        if event == "verified_exact":
            return dict(denied, attempt=replace(attempt, state="VERIFIED"))
        if event == "response_ambiguous":
            return dict(denied, attempt=replace(attempt, state="AMBIGUOUS"))
        if event == "update_rejected":
            return dict(denied, attempt=replace(attempt, state="FAILED_CLOSED"))
    return dict(denied, reason="Forbidden transition or replay")
