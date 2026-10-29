"""Non-authorizing preview of an atomic two-ref GitHub updateRefs mutation.

Reuses the already-validated two-stage plan; never modifies the existing
executor or upgrades any write authorization. No network / secrets.
"""
from scripts.phase_c_atomic_refs_plan import MUTATION, ZERO_SHA
from scripts.phase_c_c2_inert_graphql_envelope import inspect_two_stage_envelopes

NONCE = "refs/tags/phase-c-once-issue-427"
SANDBOX = "refs/heads/test/phase-c-writer-fixture-20261029"


def propose_atomic_combined(reservation, advance):
    deny = {"decision": "DENY", "write_allowed": False,
            "network_allowed": False, "retry_allowed": False,
            "activation_allowed": False}
    assessment = inspect_two_stage_envelopes(reservation, advance)
    if assessment.get("decision") != "INERT_ENVELOPES_VALIDATED":
        return dict(deny, reason="Untrusted staged envelopes")
    reserve = reservation["variables"]["input"]
    move = advance["variables"]["input"]
    first = dict(reserve["refUpdates"][0])
    second = dict(move["refUpdates"][0])
    if (first["name"] != NONCE or second["name"] != SANDBOX
            or first["beforeOid"] != ZERO_SHA
            or second["beforeOid"] != first["afterOid"]
            or first["force"] is not False or second["force"] is not False):
        return dict(deny, reason="Unsafe combined conditions")
    result = {"query": MUTATION,
              "variables": {"input": {
                  "repositoryId": reserve["repositoryId"],
                  "clientMutationId": reserve["clientMutationId"].replace(
                      "phase-c-reserve-", "phase-c-atomic-", 1),
                  "refUpdates": [first, second]}}}
    return dict(deny, decision="REVIEW_ONLY", envelope=result,
                reason="Atomic schema proposal only; App/ruleset effects unverified")
