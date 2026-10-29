"""Integrated C2 preparation: authenticated readback and hard-disabled mutation.

The GitHub App installation token is only ever used for the fixed, read-only
repository identity query. An executable writer must be a separately reviewed
module with explicit owner authorization and least-privilege credentials.
No code here can submit a GitHub GraphQL mutation.
"""
from scripts.phase_c_c2_graphql_readonly_transport import fetch_repository
from scripts.phase_c_combined_preflight import verify_preflight

NO_WRITE = {"write_allowed": False, "retry_allowed": False,
            "mutation_allowed": False, "production_authorized": False}


def inspect_with_app(token, evidence, reservation, advance, *,
                     enabled=False, opener=None):
    """Never trust injected preflight booleans as credential/permission proof."""
    deny = dict(NO_WRITE, decision="DENY")
    if enabled is not True:
        return dict(deny, reason="Explicit read-only verification not enabled")
    readback = fetch_repository(token, enabled=True, opener=opener)
    if readback.get("decision") != "READ_ONLY_VERIFIED":
        return dict(deny, reason="Authenticated repository identity not verified")
    preflight = verify_preflight(evidence, reservation, advance)
    if preflight.get("decision") != "REVIEW_ONLY":
        return dict(deny, reason="Offline combined preflight refused")
    return dict(deny, decision="READ_ONLY_REVIEW",
                reason="Repository readback plus offline proposal; not App writer identity",
                repository_id=readback["repository_id"])


def execute_one_shot(*_args, **_kwargs):
    """Unconditionally disabled; caller-supplied token and transports ignored."""
    return dict(NO_WRITE, decision="DENY", operations=[],
                reason="No write-capable transport implemented or authorized")
