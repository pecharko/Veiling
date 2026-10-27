#!/usr/bin/env python3
"""B3.2 isolated transactional uniqueness model. No DB, I/O, secrets or dispatch.

A *real* backend must prove unique constraints and atomic commit separately.
This model demonstrates the intended collision and unknown-outcome policy.
"""
from controller_one_shot_claim_contract import claim_key


def claim_dimensions(key):
    if not isinstance(key, tuple) or len(key) != 5 or key != claim_key(*key):
        raise ValueError("Invalid immutable claim")
    repo, issue, comment, release_id, sha = key
    return ((repo, issue, comment), (repo, release_id, sha))


def simulate_atomic_claim(existing_claims, key, *, result="committed"):
    """Pure immutable test transition, explicitly NEVER dispatch-capable.

    Commit result 'unknown' is blocked even if an external transaction possibly
    succeeded. The caller must not treat this model as a durable database.
    """
    authorization, release = claim_dimensions(key)
    if not isinstance(existing_claims, tuple) or any(
            not isinstance(row, tuple) or len(row) != 2
            or not isinstance(row[0], tuple) or not isinstance(row[1], tuple)
            for row in existing_claims):
        raise ValueError("Invalid simulated ledger")
    if result not in ("committed", "unknown", "rejected"):
        raise ValueError("Invalid transaction outcome")
    if result == "unknown":
        return existing_claims, {"status": "UNKNOWN_BLOCKED",
                                 "dispatch_allowed": False,
                                 "automatic_retry_allowed": False}
    if result == "rejected":
        return existing_claims, {"status": "REJECTED",
                                 "dispatch_allowed": False,
                                 "automatic_retry_allowed": False}
    if any(authorization == a or release == b for a, b in existing_claims):
        return existing_claims, {"status": "UNIQUE_CONFLICT",
                                 "dispatch_allowed": False,
                                 "automatic_retry_allowed": False}
    # A commit in a toy model is not evidence of a trusted external CAS.
    return existing_claims + ((authorization, release),), {
        "status": "MODEL_COMMIT_NOT_AUTHORITY",
        "dispatch_allowed": False,
        "automatic_retry_allowed": False,
    }
