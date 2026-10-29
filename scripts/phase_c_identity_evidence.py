"""Offline Phase C actor-separation evidence checks. Never authorizes a write.

Claims are untrusted input. Even complete evidence remains non-operational.
"""
from dataclasses import dataclass

REPO = "pecharko/Veiling"
HEAD = "2595a63c6cd575dbbb7f29f293ccccaccc140553"
ISSUE = 427


@dataclass(frozen=True)
class IdentityEvidence:
    repository: str
    pr_head: str
    issue_number: int
    owner_login: str
    issue_author: str
    comment_author: str
    ci_actor: str
    writer_actor: str
    writer_kind: str
    token_permission_verified: bool
    branch_rule_verified: bool
    tag_rule_verified: bool


def inspect_identity(evidence):
    deny = {"decision": "DENY", "write_allowed": False, "retry_allowed": False}
    if type(evidence) is not IdentityEvidence:
        return dict(deny, reason="Malformed or absent identity evidence")
    if (evidence.repository != REPO or evidence.pr_head != HEAD
            or type(evidence.issue_number) is not int or evidence.issue_number != ISSUE):
        return dict(deny, reason="Immutable scope mismatch")
    actors = (evidence.owner_login, evidence.issue_author,
              evidence.comment_author, evidence.ci_actor, evidence.writer_actor)
    if any(type(actor) is not str or not actor or actor.strip() != actor for actor in actors):
        return dict(deny, reason="Missing actor identity")
    if (evidence.issue_author != evidence.owner_login
            or evidence.comment_author != evidence.owner_login):
        return dict(deny, reason="Owner attribution mismatch")
    if (evidence.writer_actor == evidence.ci_actor
            or evidence.writer_actor == evidence.owner_login
            or evidence.writer_kind not in ("github_app", "dedicated_service")):
        return dict(deny, reason="Writer actor not independently isolated")
    if any(type(v) is not bool or not v for v in
           (evidence.token_permission_verified, evidence.branch_rule_verified,
            evidence.tag_rule_verified)):
        return dict(deny, reason="Security evidence unverified")
    return dict(deny, reason="Offline claims are not trusted API identity proof",
                evidence_consistent=True)
