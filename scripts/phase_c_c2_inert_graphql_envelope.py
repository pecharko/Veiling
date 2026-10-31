"""Offline GraphQL two-stage wire-envelope validator; cannot perform HTTP.

This independently validates the planned exact mutation payload shapes.
Returned decisions are NEVER execution authority.
"""
from scripts.phase_c_atomic_refs_plan import MUTATION, ZERO_SHA

NONCE = "refs/tags/phase-c-once-issue-427"
BRANCH = "refs/heads/test/phase-c-writer-fixture-20261029"
HEAD = "2595a63c6cd575dbbb7f29f293ccccaccc140553"
REPOSITORY_NODE = "R_kgDOVNLxvg"


def inspect_two_stage_envelopes(reservation, advance):
    deny = {"decision": "DENY", "write_allowed": False,
            "retry_allowed": False, "network_allowed": False}
    if not isinstance(reservation, dict) or not isinstance(advance, dict):
        return dict(deny, reason="Non-object envelope")
    try:
        first = reservation["variables"]["input"]
        second = advance["variables"]["input"]
        a, b = first["refUpdates"], second["refUpdates"]
        if (reservation["query"] != MUTATION or advance["query"] != MUTATION
                or first["repositoryId"] != REPOSITORY_NODE
                or second["repositoryId"] != REPOSITORY_NODE
                or type(a) is not list or len(a) != 1
                or type(b) is not list or len(b) != 1
                or type(a[0]) is not dict or type(b[0]) is not dict):
            return dict(deny, reason="Untrusted mutation envelope")
        nonce, branch = a[0], b[0]
        if (set(nonce) != {"name", "beforeOid", "afterOid", "force"}
                or set(branch) != {"name", "beforeOid", "afterOid", "force"}
                or nonce != {"name": NONCE, "beforeOid": ZERO_SHA,
                             "afterOid": HEAD, "force": False}
                or branch["name"] != BRANCH or branch["beforeOid"] != HEAD
                or branch["force"] is not False
                or type(branch["afterOid"]) is not str
                or len(branch["afterOid"]) != 40
                or any(c not in "0123456789abcdef" for c in branch["afterOid"])
                or branch["afterOid"] == HEAD):
            return dict(deny, reason="Unsafe ref update")
        if (set(first) != {"repositoryId", "clientMutationId", "refUpdates"}
                or set(second) != {"repositoryId", "clientMutationId", "refUpdates"}
                or type(first["clientMutationId"]) is not str
                or type(second["clientMutationId"]) is not str
                or not first["clientMutationId"].startswith("phase-c-reserve-427-")
                or not second["clientMutationId"].startswith("phase-c-advance-427-")):
            return dict(deny, reason="Invalid immutable mutation metadata")
    except (KeyError, TypeError, AttributeError):
        return dict(deny, reason="Malformed envelope")
    return dict(deny, decision="INERT_ENVELOPES_VALIDATED",
                reason="Validated offline only; no mutation transport exists")


def submit(*_args, **_kwargs):
    """Never execute; no token, GraphQL client, HTTP or activation switch."""
    return {"decision": "DENY", "write_allowed": False, "retry_allowed": False,
            "network_allowed": False, "operations": []}
