"""C2 write-transport seam: always disabled in production and tests.

Contains a strict GraphQL request builder for independent review.
Does NOT implement a network sender, token reader or activation switch.
"""
import json
from scripts.phase_c_c2_inert_graphql_envelope import inspect_two_stage_envelopes


class DisabledTransport:
    def __init__(self):
        self.operations = []

    def post(self, *_args, **_kwargs):
        """Never sends HTTP, even with injected headers or token."""
        return {"decision": "DENY", "network_allowed": False,
                "write_allowed": False, "retry_allowed": False}


def build_review_packet(reservation, advance):
    """Return deterministic offline JSON only for exact validated two-stage scope."""
    refusal = {"decision": "DENY", "network_allowed": False,
               "write_allowed": False, "retry_allowed": False}
    assessment = inspect_two_stage_envelopes(reservation, advance)
    if assessment["decision"] != "INERT_ENVELOPES_VALIDATED":
        return dict(refusal, reason="Unsafe offline mutation envelopes")
    return dict(refusal, decision="REVIEW_PACKET_ONLY",
                reservation_json=json.dumps(reservation, sort_keys=True, separators=(",", ":")),
                advance_json=json.dumps(advance, sort_keys=True, separators=(",", ":")),
                reason="Trusted executor and authenticated sender not implemented")


def execute(*_args, **_kwargs):
    """Hard deny. Future authenticated mutation transport requires distinct review."""
    return {"decision": "DENY", "network_allowed": False,
            "write_allowed": False, "retry_allowed": False, "operations": []}
