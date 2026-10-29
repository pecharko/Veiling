"""Pure offline simulator for phase C single-file branch-head compare-and-swap.

Not a GitHub writer; does not perform HTTP, file mutations, or grant permissions.
"""
import hashlib
import re

SHA = re.compile(r"^[0-9a-f]{40}$")
TARGET = "tests/phase_c_sandbox_test.php"


def simulate_cas_transaction(approval, snapshot, proposed):
    denied = {"accepted": False, "can_write": False, "can_merge": False,
              "can_dispatch": False, "reason": "invalid input"}
    if not all(isinstance(x, dict) for x in (approval, snapshot, proposed)):
        return denied
    for key in ("head_sha", "blob_sha"):
        expected = approval.get(key)
        if not isinstance(expected, str) or not SHA.fullmatch(expected):
            return {**denied, "reason": "invalid sha"}
        if snapshot.get(key) != expected or proposed.get("observed_" + key) != expected:
            return {**denied, "reason": "drift"}
    if approval.get("target_path") != TARGET or proposed.get("path") != TARGET:
        return {**denied, "reason": "path denied"}
    before = snapshot.get("content")
    after = proposed.get("content")
    if not isinstance(before, str) or not isinstance(after, str):
        return {**denied, "reason": "invalid content"}
    if before.count("return flase;") != 1:
        return {**denied, "reason": "ambiguous correction"}
    if after != before.replace("return flase;", "return false;", 1):
        return {**denied, "reason": "non-deterministic change"}
    digest = hashlib.sha256(after.encode("utf-8")).hexdigest()
    if approval.get("result_sha256") != digest:
        return {**denied, "reason": "result digest mismatch"}
    if proposed.get("nonce_reserved_atomically") is not True:
        return {**denied, "reason": "nonce not reserved"}
    return {"accepted": True, "can_write": False, "can_merge": False,
            "can_dispatch": False, "reason": "offline simulation only",
            "result_sha256": digest}
