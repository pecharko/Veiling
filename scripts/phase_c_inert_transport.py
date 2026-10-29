"""Inert Phase C transport boundary: inspect evidence, never send requests.

There is deliberately no HTTP client, GitHub SDK, token reader, write method,
or activation mechanism. This is a proof-obligation validator, not a writer.
"""
from dataclasses import dataclass

from scripts.phase_c_disabled_writer import Transaction, prepare_transaction


@dataclass(frozen=True)
class ReadOnlyProof:
    """Claims are not credentials; trust must be established outside this module."""
    pr_head: str
    blob_sha: str
    owner_comment_verified: bool
    actor_identity_verified: bool
    permission_scope_verified: bool
    tag_rules_verified: bool
    branch_rules_verified: bool
    nonce_absence_verified_atomically: bool
    expected_branch_update_cas_verified: bool


def assess_proof(proof: ReadOnlyProof, transaction: Transaction) -> dict:
    """Fail closed even for fully positive untrusted proof claims."""
    denied = {"decision": "DENY", "write_allowed": False, "retry_allowed": False}
    if type(proof) is not ReadOnlyProof:
        return dict(denied, reason="Malformed proof")
    if prepare_transaction(transaction)["decision"] != "PREPARED_INACTIVE":
        return dict(denied, reason="Transaction scope mismatch")
    if proof.pr_head != transaction.head or proof.blob_sha != transaction.blob:
        return dict(denied, reason="Immutable input drift")
    fields = (proof.owner_comment_verified, proof.actor_identity_verified,
              proof.permission_scope_verified, proof.tag_rules_verified,
              proof.branch_rules_verified, proof.nonce_absence_verified_atomically,
              proof.expected_branch_update_cas_verified)
    if any(type(v) is not bool or not v for v in fields):
        return dict(denied, reason="Unproven security obligation")
    return dict(denied, reason="Untrusted evidence cannot activate transport",
                proof_complete_in_simulation=True)


def transport_request(*args, **kwargs) -> dict:
    """Permanent deny stub; no injected client is ever examined or called."""
    return {"decision": "DENY", "reason": "No authenticated transport implemented",
            "write_allowed": False, "retry_allowed": False, "operations": []}
