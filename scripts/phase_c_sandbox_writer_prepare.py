"""Offline preparation for an eventual GitHub sandbox writer.

A real writer is deliberately absent. This module prepares an auditable
comparison-only plan; it has no HTTP client, token access or filesystem writes.
"""
import hashlib
from scripts.phase_c_sandbox_transaction import plan_sandbox_repair


def verify_prepared_transaction(evidence, expected_content_sha256):
    result = plan_sandbox_repair(evidence)
    if result["decision"] != "PROPOSAL_ONLY":
        return {"decision": "DENY", "reason": result.get("reason", "Policy refused"),
                "write_allowed": False}
    if not isinstance(expected_content_sha256, str) or len(expected_content_sha256) != 64:
        return {"decision": "DENY", "reason": "Missing new content digest", "write_allowed": False}
    actual = hashlib.sha256(evidence["new_content"].encode("utf-8")).hexdigest()
    if expected_content_sha256 != actual or result["proposed_sha256"] != actual:
        return {"decision": "DENY", "reason": "Proposed content SHA mismatch",
                "write_allowed": False}
    return {"decision": "READY_FOR_SEPARATE_AUTHORIZATION",
            "head_sha": result["expected_head"],
            "blob_sha": result["expected_blob"],
            "path": result["file_path"],
            "new_content_sha256": actual,
            "write_allowed": False, "merge_allowed": False, "deployment_allowed": False}
