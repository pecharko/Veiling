"""Independent post-write readback verifier for Phase C2 (READ-ONLY).

This file never posts mutations, handles credentials, or grants execution
authority. Caller must source both GitHub GET results independently.
"""
from scripts.phase_c_c2_owner_gate import HEAD, BRANCH, NONCE

CANDIDATE = "11ead85e1a3d0086166da665a1a6d4bdf25477d7"


def verify_refs(branch_get, nonce_get):
    """Classify two authenticated GitHub REST GET responses, no retries."""
    deny = {"decision": "DENY", "write_allowed": False,
            "retry_allowed": False, "mutation_allowed": False,
            "production_authorized": False}
    if type(branch_get) is not dict or type(nonce_get) is not dict:
        return dict(deny, reason="Readback missing")
    if set(branch_get) != {"ref", "object"} or set(nonce_get) != {"ref", "object"}:
        return dict(deny, reason="Readback schema drift")
    branch_obj, nonce_obj = branch_get["object"], nonce_get["object"]
    if type(branch_obj) is not dict or type(nonce_obj) is not dict:
        return dict(deny, reason="Object structure missing")
    if (branch_get["ref"] != BRANCH or nonce_get["ref"] != NONCE
            or set(branch_obj) != {"type", "sha"} or set(nonce_obj) != {"type", "sha"}
            or branch_obj["type"] != "commit" or nonce_obj["type"] != "commit"
            or branch_obj["sha"] != CANDIDATE or nonce_obj["sha"] != HEAD):
        return dict(deny, reason="Unexpected ref state")
    return dict(deny, decision="READBACK_MATCH",
                reason="Readback values match the proposed state; not owner approval or proof of atomicity")


def authorize_or_write(*_args, **_kwargs):
    return {"decision": "DENY", "write_allowed": False,
            "network_allowed": False, "operations": []}
