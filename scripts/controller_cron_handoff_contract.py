"""B3.3 *offline only* cron handoff validation, never dispatch or write.

A candidate is NOT a claim. GitHub-supplied fields must not confer authority.
"""
import hashlib
import hmac
import json
import time

from controller_one_shot_claim_contract import claim_key

_ALLOWED = frozenset(("repository", "issue_id", "comment_id", "release_id", "release_sha",
                      "issued_at", "expires_at", "nonce"))
MAX_TTL = 300


def canonical(payload):
    if not isinstance(payload, dict) or set(payload) != _ALLOWED:
        raise ValueError("Unexpected request fields")
    claim_key(*(payload[k] for k in ("repository", "issue_id", "comment_id",
                                    "release_id", "release_sha")))
    issued, expires = payload["issued_at"], payload["expires_at"]
    if type(issued) is not int or type(expires) is not int or not 0 < expires-issued <= MAX_TTL:
        raise ValueError("Invalid lifetime")
    nonce = payload["nonce"]
    if not isinstance(nonce, str) or len(nonce) != 64 or any(c not in "0123456789abcdef" for c in nonce):
        raise ValueError("Invalid nonce")
    return json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=True).encode("ascii")


def assess_handoff(payload, signature, secret, *, now=None, independent_replay_proven=False):
    """Fail closed, even after valid MAC: independent authority is mandatory."""
    blocked = {"status": "BLOCKED", "dispatch_allowed": False, "write_allowed": False,
               "automatic_retry_allowed": False}
    try:
        raw = canonical(payload)
        if not isinstance(secret, bytes) or len(secret) < 32:
            raise ValueError("Unsafe key")
        if not isinstance(signature, str) or len(signature) != 64:
            raise ValueError("Invalid signature")
        expected = hmac.new(secret, raw, hashlib.sha256).hexdigest()
        if not hmac.compare_digest(expected, signature):
            raise ValueError("MAC mismatch")
        timestamp = int(time.time()) if now is None else now
        if type(timestamp) is not int or timestamp < payload["issued_at"] - 30 or timestamp > payload["expires_at"]:
            raise ValueError("Expired / future handoff")
        if independent_replay_proven is not True:
            return {**blocked, "reason": "independent_restore_safe_replay_proof_missing"}
        # The offline validator NEVER grants write or dispatch; only a separate
        # approved atomic durable executor could attempt a claim.
        return {**blocked, "reason": "production_executor_not_authorized"}
    except (TypeError, ValueError, OverflowError):
        return {**blocked, "reason": "invalid_handoff"}
