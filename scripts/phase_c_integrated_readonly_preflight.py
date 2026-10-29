"""Integrated advisory Phase C preflight; NEVER authorizes GitHub writes.

api_get is injected. State and nonce flags are untrusted simulations, not
proof of a durable atomic reservation or a real Git ref compare-and-swap.
"""
from scripts.phase_c_github_evidence_reader import collect_and_check
from scripts.phase_c_one_shot_preflight import check_one_shot_preflight
from scripts.phase_c_cas_offline import simulate_cas_transaction

def evaluate_integrated_readonly(api_get, pr_number, issue_number, comment_id,
                                 auth, snapshot, proposed):
    denied = {"verified": False, "can_write": False, "can_merge": False,
              "can_dispatch": False, "decision": "DENY"}
    owner = collect_and_check(api_get, pr_number, issue_number, comment_id, auth)
    if owner.get("verified") is not True:
        return dict(denied, stage="owner", reason=owner.get("reason", "Owner denied"))
    if not isinstance(snapshot, dict) or not isinstance(proposed, dict):
        return dict(denied, stage="inputs", reason="Invalid snapshot or proposal")
    approval = {
        "issue_id": auth.get("issue_id"), "comment_id": auth.get("comment_id"),
        "pr_number": pr_number, "head_sha": owner["head_sha"],
        "blob_sha": owner["blob_sha"], "target_path": owner["path"],
        "new_sha256": auth.get("new_sha256"),
    }
    current = {
        "head_sha": snapshot.get("head_sha"),
        "blob_sha": snapshot.get("blob_sha"),
        "target_path": snapshot.get("target_path"),
        "owner_verified": True,
        "nonce_reserved_atomically": False,
        "nonce_replayed": None,
        "pr_is_open": True,
        "from_fork": False,
    }
    reservation = check_one_shot_preflight(approval, current)
    # The integrated reader is deliberately prohibited from fabricating proof
    # of a real durable nonce reservation. The CAS simulator is advisory only.
    if reservation.get("decision") != "READY_FOR_TRUSTED_WRITER_REVIEW":
        return dict(denied, stage="nonce", reason=reservation.get("reason"))
    return dict(denied, stage="disabled", reason="Writer activation is not implemented")

def inspect_proposal_offline(approval, snapshot, proposed):
    """Optional diagnostic, still never a write grant."""
    verdict = simulate_cas_transaction(approval, snapshot, proposed)
    return {"accepted_offline": verdict.get("accepted") is True,
            "can_write": False, "can_merge": False, "can_dispatch": False,
            "reason": verdict.get("reason")}
