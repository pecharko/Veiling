#!/usr/bin/env python3
"""Phase C8 closure: pure, fail-closed rehearsal for a FUTURE trusted writer.

Coordinates existing C7 read-only review and existing C2 attempt state model.
All events are simulated in memory. No GitHub credentials, network, files,
GraphQL, nonce creation, ref updates, Actions or activation switches exist.
"""
import hashlib
import json

from scripts.development_ci_c7_activation_review import review_activation_boundary
from scripts.phase_c_c2_attempt_state import Attempt, transition

SAFE = {
    "can_write": False,
    "write_allowed": False,
    "can_merge": False,
    "can_dispatch": False,
    "retry_allowed": False,
    "activation_allowed": False,
    "production_authorized": False,
    "owner_live_write_approved": False,
    "owner_identity_verified": False,
    "app_token_authenticated": False,
    "source_provenance_authenticated": False,
    "nonce_reserved": False,
    "git_ref_updates_executed": 0,
}
BLOCKERS = [
    "fresh_authenticated_owner_approval_for_exact_live_operation",
    "fresh_authenticated_app_and_ruleset_coverage",
    "separately_reviewed_trusted_writer_isolation",
    "new_atomic_durable_nonce_and_non_force_head_CAS",
    "independent_postwrite_readback_and_current_PR_CI",
]
EVENTS = ("reserve_confirmed", "submit_ref_update", "verified_exact")
MAX_EVENTS = 3


def closure_dry_run(
    source, issue, comment, app, installation, repositories,
    branch_rules, tag_rules, nonce_state, events,
):
    """Simulate exactly one nonce/branch update state machine, NEVER execute.

    This is deliberately NOT an authorization parser or live writer API.
    A forged C7-shaped record cannot authorize anything. The C7 review is
    recomputed from supplied offline inputs, which remain untrusted data.
    'verified_exact' denotes only a SIMULATED state transition, never proof
    of an actual GitHub change.
    """
    report = {
        **SAFE,
        "decision": "DENY",
        "reason_code": "OFFLINE_REVIEW_OR_STATE_MACHINE_REJECTED",
        "scope": "pecharko/Veiling:future-phase-c-repair-dryrun",
        "simulated_state": "NOT_STARTED",
        "simulated_nonce_spent": False,
        "simulated_ref_update_attempts": 0,
        "simulated_only": True,
        "remaining_live_gates": list(BLOCKERS),
    }

    def result():
        payload = json.dumps(
            report, sort_keys=True, separators=(",", ":"), ensure_ascii=True
        ).encode("ascii")
        report["report_sha256"] = hashlib.sha256(payload).hexdigest()
        return report

    if (type(events) is not list or not 1 <= len(events) <= MAX_EVENTS
            or any(type(x) is not str or x not in EVENTS for x in events)
            or events != list(EVENTS[:len(events)])):
        return result()

    evidence = review_activation_boundary(
        source, issue, comment, app, installation, repositories,
        branch_rules, tag_rules, nonce_state,
    )
    if (evidence.get("decision") != "READ_ONLY_SECURITY_REVIEW"
            or evidence.get("review_records_structurally_consistent") is not True
            or any(evidence.get(k) is not False for k in SAFE
                   if k in evidence and k != "git_ref_updates_executed")
            or evidence.get("git_ref_updates_executed") != 0
            or evidence.get("nonce_absence_proven") is not False
            or evidence.get("effective_ruleset_coverage_proven") is not False):
        return result()

    attempt = Attempt()
    for event in events:
        simulated = transition(attempt, event)
        next_attempt = simulated.get("attempt")
        if (type(next_attempt) is not Attempt
                or simulated.get("write_allowed") is not False
                or simulated.get("retry_allowed") is not False):
            return result()
        attempt = next_attempt

    state = {
        "NONCE_RESERVED": "OFFLINE_NONCE_REHEARSAL",
        "REF_UPDATE_ATTEMPTED": "OFFLINE_CAS_REHEARSAL",
        "VERIFIED": "OFFLINE_POSTWRITE_REHEARSAL",
    }.get(attempt.state)
    if state is None:
        return result()

    report.update({
        "decision": "OFFLINE_REHEARSAL_ONLY",
        "reason_code": state,
        "simulated_state": attempt.state,
        "simulated_nonce_spent": attempt.nonce_spent,
        "simulated_ref_update_attempts": attempt.update_attempts,
        "c7_review_sha256": evidence["report_sha256"],
        "review_issue": evidence["review_issue"],
        "pr": evidence["pr"],
        "head_sha": evidence["head_sha"],
        "path": evidence["path"],
    })
    return result()


def execute_one_shot(*_args, **_kwargs):
    """Unconditional deny, including caller-supplied tokens or enable=True."""
    return {
        **SAFE, "decision": "DENY", "operations": [],
        "reason_code": "NO_LIVE_WRITER_OR_AUTHORIZATION_INSTALLED",
    }


if __name__ == "__main__":
    raise SystemExit("C8 rehearsals are import-only; no live transaction entrypoint")
