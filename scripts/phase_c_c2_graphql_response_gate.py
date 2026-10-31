"""Offline fail-closed assessment of one-shot GraphQL mutation responses.

Validates only the response envelope. Never authorizes a real mutation or
proves nonce ownership; an authenticated trusted executor is still required.
No HTTP, tokens or filesystem access.
"""
import re

IDENTIFIER = re.compile(r"^phase-c-(reserve|advance)-427-[1-9][0-9]*-[0-9a-f]{12}$")


def inspect_response(payload, expected_mutation_id, *, phase):
    deny = {"decision": "MANUAL_REVIEW", "write_allowed": False,
            "retry_allowed": False, "next_write_allowed": False}
    if (phase not in ("reserve", "advance") or
            type(expected_mutation_id) is not str or
            not IDENTIFIER.fullmatch(expected_mutation_id) or
            not expected_mutation_id.startswith("phase-c-" + phase + "-")):
        return dict(deny, reason="Invalid trusted mutation metadata")
    if type(payload) is not dict or set(payload) - {"data", "errors", "extensions"}:
        return dict(deny, reason="Malformed GraphQL response")
    if "errors" in payload or payload.get("data") is None:
        return dict(deny, reason="GraphQL errors or missing data")
    data = payload["data"]
    if type(data) is not dict or set(data) != {"updateRefs"}:
        return dict(deny, reason="Unexpected GraphQL data")
    result = data["updateRefs"]
    if (type(result) is not dict or set(result) != {"clientMutationId"} or
            result["clientMutationId"] != expected_mutation_id):
        return dict(deny, reason="Missing or mismatched mutation acknowledgement")
    # A valid ack requires independent authenticated state verification.
    return dict(deny, decision="ACK_ONLY", reason="Response not proof of durable mutation")
