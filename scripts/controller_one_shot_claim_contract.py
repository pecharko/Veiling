#!/usr/bin/env python3
"""B3.2 pure idempotency contract: no storage, network or dispatch.

Only an external durable compare-and-set authority can consume a claim.
Unknown results fail closed and MUST NOT trigger automatic retries.
"""
import re

SHA = re.compile(r"^[0-9a-f]{40}$")
ID = re.compile(r"^[a-z0-9][a-z0-9-]{2,60}$")
STATES = frozenset(("UNCLAIMED", "CLAIMED", "DISPATCH_UNKNOWN", "DISPATCH_CONFIRMED", "CLOSED"))


def claim_key(repository, issue_id, comment_id, release_id, release_sha):
    if (repository != "pecharko/Veiling"
            or type(issue_id) is not int or issue_id <= 0
            or type(comment_id) is not int or comment_id <= 0
            or not isinstance(release_id, str) or not ID.fullmatch(release_id)
            or not isinstance(release_sha, str) or not SHA.fullmatch(release_sha)):
        raise ValueError("Invalid immutable authorization identity")
    return ("pecharko/Veiling", issue_id, comment_id, release_id, release_sha)


def review_claim(key, record, *, durable_cas_proven):
    """Inert review only; NEVER authorize dispatch from this return value.

    A future dispatcher needs atomic durable CAS plus owner authorization,
    not this function. No record and a False durable proof are not permission.
    """
    if not isinstance(key, tuple) or len(key) != 5 or key != claim_key(*key):
        raise ValueError("Invalid claim key")
    if durable_cas_proven is not True:
        return {"status": "BLOCKED", "reason": "durable_atomic_claim_not_proven",
                "dispatch_allowed": False}
    if not isinstance(record, dict) or set(record) != {"key", "state", "run_id"}:
        return {"status": "BLOCKED", "reason": "missing_or_invalid_durable_record",
                "dispatch_allowed": False}
    if record["key"] != key or record["state"] not in STATES:
        return {"status": "BLOCKED", "reason": "claim_mismatch",
                "dispatch_allowed": False}
    state = record["state"]
    run_id = record["run_id"]
    if ((state == "DISPATCH_CONFIRMED" and
         (type(run_id) is not int or run_id <= 0))
            or (state != "DISPATCH_CONFIRMED" and run_id is not None)):
        return {"status": "BLOCKED", "reason": "invalid_run_provenance",
                "dispatch_allowed": False}
    # Even an UNCLAIMED record is insufficient without an atomic transition.
    return {"status": "BLOCKED", "reason": "external_one_shot_gate_required",
            "claim_state": state, "dispatch_allowed": False,
            "automatic_retry_allowed": False}


def assess_create_only_outcome(key, *, http_status, observed_ref, expected_target_sha):
    """Pure model for an external atomic create-ref transaction; never dispatch.

    A 201 is only a claimed API response, not proof of exclusive immutable
    ownership. A 422, timeout, or conflicting readback always fail closed.
    """
    if not isinstance(key, tuple) or len(key) != 5 or key != claim_key(*key):
        raise ValueError("Invalid canonical claim")
    if not isinstance(expected_target_sha, str) or not SHA.fullmatch(expected_target_sha):
        raise ValueError("Invalid expected target")
    if http_status not in (201, 422, None):
        return {"status": "BLOCKED", "reason": "unexpected_api_outcome",
                "dispatch_allowed": False, "automatic_retry_allowed": False}
    if http_status is None:
        reason = "create_result_unknown"
    elif http_status == 422:
        reason = "claim_already_exists_or_conflict"
    else:
        reason = "unproven_exclusive_persistence"
    if observed_ref is not None:
        if not isinstance(observed_ref, dict) or set(observed_ref) != {"key", "target_sha"}:
            reason = "invalid_ref_readback"
        elif observed_ref["key"] != key or observed_ref["target_sha"] != expected_target_sha:
            reason = "ref_identity_or_target_drift"
        elif http_status == 201:
            reason = "matching_ref_not_proof_of_irreversibility"
    return {"status": "BLOCKED", "reason": reason,
            "dispatch_allowed": False, "automatic_retry_allowed": False}
