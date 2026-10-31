"""Phase C2 reviewed transport boundary: only inert, immutable request data.

This module does not load credentials, open sockets, import HTTP clients,
execute actions or grant approval. A separate privileged implementation must
authenticate the GitHub App and obtain fresh independent evidence before use.
"""
from scripts.phase_c_atomic_refs_plan import MUTATION, ZERO_SHA
from scripts.phase_c_c2_inert_graphql_envelope import NONCE, BRANCH, HEAD, REPOSITORY_NODE

CANDIDATE = "11ead85e1a3d0086166da665a1a6d4bdf25477d7"
_DENIED = {"decision": "DENY", "network_allowed": False, "write_allowed": False,
           "retry_allowed": False, "production_allowed": False, "operations": []}


def inspect_transport_payload(payload):
    """Match exact one-call CAS+nonce wire data, never authorize sending it."""
    if type(payload) is not dict or set(payload) != {"query", "variables"}:
        return dict(_DENIED, reason="Envelope schema invalid")
    if payload["query"] != MUTATION or type(payload["variables"]) is not dict:
        return dict(_DENIED, reason="Unexpected GraphQL query")
    if set(payload["variables"]) != {"input"}:
        return dict(_DENIED, reason="Unexpected variables")
    inp = payload["variables"]["input"]
    if type(inp) is not dict or set(inp) != {
        "repositoryId", "clientMutationId", "refUpdates"
    }:
        return dict(_DENIED, reason="Unexpected mutation input")
    if inp["repositoryId"] != REPOSITORY_NODE:
        return dict(_DENIED, reason="Repository mismatch")
    mutation_id = inp["clientMutationId"]
    if type(mutation_id) is not str or not mutation_id.startswith("phase-c-atomic-427-"):
        return dict(_DENIED, reason="Unexpected nonce binding")
    expected = [
        {"name": NONCE, "beforeOid": ZERO_SHA, "afterOid": HEAD, "force": False},
        {"name": BRANCH, "beforeOid": HEAD, "afterOid": CANDIDATE, "force": False},
    ]
    updates = inp["refUpdates"]
    if type(updates) is not list or updates != expected:
        return dict(_DENIED, reason="Atomic ref updates not exact")
    return dict(_DENIED, decision="REVIEW_ONLY",
                reason="Inert payload only, independent live prerequisites absent")


def send(*_args, **_kwargs):
    """Explicitly non-operational, including when supplied a token/opener."""
    return dict(_DENIED, reason="Live transport requires separate security review and authorization")
