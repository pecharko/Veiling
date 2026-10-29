"""Phase C2 trusted-executor boundary: default-deny, no live credentials.

An offline review is not an authorization. This component deliberately has
no operation for issuing a GraphQL request; a future live adapter requires
independent review and a separate exact one-time owner authorization.
"""
from scripts.phase_c_c2_owner_gate import review_owner_scope
from scripts.phase_c_combined_preflight import verify_preflight
from scripts.phase_c_c2_guarded_atomic_sender import inspect_atomic_envelope

DENIED = {"decision": "DENY", "network_allowed": False,
          "write_allowed": False, "retry_allowed": False,
          "production_allowed": False, "operations": []}


def review_transaction(owner_record, evidence, reservation, advance):
    """Offline coordination; no untrusted report can become execution authority."""
    scope = review_owner_scope(owner_record)
    preflight = verify_preflight(evidence, reservation, advance)
    envelope = inspect_atomic_envelope(reservation, advance)
    if (scope.get("decision") != "REVIEW_ONLY"
            or preflight.get("decision") != "REVIEW_ONLY"
            or envelope.get("decision") != "REVIEW_ONLY"):
        return dict(DENIED, reason="Required independent reviews not satisfied")
    if owner_record["candidate_oid"] != preflight.get("candidate_oid"):
        return dict(DENIED, reason="Candidate mismatch between reviews")
    return dict(DENIED, decision="READY_FOR_INDEPENDENT_REVIEW",
                reason="Offline evidence is not authenticated owner or App identity")


def execute_one_shot(*_args, **_kwargs):
    """No runtime switch, token, injectable HTTP transport, or write bypass."""
    return dict(DENIED, reason="Live write adapter not installed")
