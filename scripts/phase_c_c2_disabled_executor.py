"""Credential-isolated Phase C writer boundary. Intentionally inoperable.

The module models the future trusted executor; it cannot send requests.
Enabling the writer requires a separate code review, permissions approval,
and exact one-shot sandbox authorization. Never run PR-controlled code with
a GitHub App credential.
"""
from dataclasses import dataclass
from scripts.phase_c_disabled_writer import Transaction, prepare_transaction
from scripts.phase_c_c2_inert_graphql_envelope import inspect_two_stage_envelopes
from scripts.phase_c_c2_graphql_response_gate import inspect_response

WRITER_ENABLED = False
APP_INSTALLATION = 176547610
REPOSITORY = "pecharko/Veiling"
SANDBOX_PR = 426
OWNER_ISSUE = 427


@dataclass(frozen=True)
class TrustedContext:
    repository: str
    installation_id: int
    pr_number: int
    issue_number: int
    owner_authenticated: bool
    comment_authenticated: bool
    rulesets_effective_for_writer: bool
    one_shot_authorized: bool
    pinned_main_commit: str


def assess_boundary(context, transaction, reservation, advance):
    """Prepare non-authorizing report; all claims are untrusted inputs."""
    deny = {"decision": "DENY", "write_allowed": False,
            "network_allowed": False, "retry_allowed": False,
            "dispatch_allowed": False, "production_authorized": False}
    if type(context) is not TrustedContext or type(transaction) is not Transaction:
        return dict(deny, reason="Invalid immutable inputs")
    if (context.repository != REPOSITORY or
            type(context.installation_id) is not int or
            context.installation_id != APP_INSTALLATION or
            type(context.pr_number) is not int or context.pr_number != SANDBOX_PR or
            type(context.issue_number) is not int or context.issue_number != OWNER_ISSUE or
            prepare_transaction(transaction)["decision"] != "PREPARED_INACTIVE"):
        return dict(deny, reason="Scope drift")
    obligations = (context.owner_authenticated, context.comment_authenticated,
                   context.rulesets_effective_for_writer, context.one_shot_authorized)
    if any(value is not True for value in obligations):
        return dict(deny, reason="Trusted evidence incomplete")
    if (type(context.pinned_main_commit) is not str or
            len(context.pinned_main_commit) != 40 or
            any(c not in "0123456789abcdef" for c in context.pinned_main_commit)):
        return dict(deny, reason="Missing pinned trusted main")
    result = inspect_two_stage_envelopes(reservation, advance)
    if result["decision"] != "INERT_ENVELOPES_VALIDATED":
        return dict(deny, reason="Unsafe two-stage envelope")
    return dict(deny, decision="REVIEW_ONLY",
                reason="Untrusted claims are not identity or activation proof")


def inspect_mutation_ack(payload, expected_id, phase):
    """Separate offline response verification; never authorizes next step."""
    return inspect_response(payload, expected_id, phase=phase)


def execute(*_args, **_kwargs):
    """Permanently refuse, even if a caller injects a transport or token."""
    return {"decision": "DENY", "reason": "Trusted writer not activated",
            "write_allowed": False, "network_allowed": False,
            "retry_allowed": False, "operations": []}
