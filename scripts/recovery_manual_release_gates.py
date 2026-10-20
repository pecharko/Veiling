#!/usr/bin/env python3
"""Fail-closed offline release authorization evaluator.

IMPORTANT: attestations are assertions, not proof. This module cannot grant
deployment or contact OVH. Humans must independently verify evidence.
"""
from __future__ import annotations
import re

REQUIRED = (
    "three_ci_checks_on_exact_head",
    "offline_manifest_reviewed",
    "fresh_remote_baseline_verified",
    "no_restore_markers",
    "host_key_and_root_verified",
    "verified_private_code_backup_persisted",
    "external_data_only_backup_verified",
    "php_mysql_compatibility_verified",
    "maintenance_window_secured",
    "rollback_and_smoke_plan_reviewed",
    "specific_release_consent",
    "separate_final_deploy_consent",
)

def assess_release_gate(evidence: dict, checkout_sha: str) -> dict:
    """Only report readiness for manual review; never authorize an upload."""
    if not isinstance(evidence, dict) or not re.fullmatch(r"[0-9a-f]{40}", checkout_sha):
        raise ValueError("Invalid evidence or exact release SHA")
    if evidence.get("release_sha") != checkout_sha:
        raise ValueError("Release commit mismatch")
    unknown = set(evidence) - (set(REQUIRED) | {"release_sha"})
    if unknown:
        raise ValueError("Unexpected authorization evidence fields")
    missing = [key for key in REQUIRED if evidence.get(key) is not True]
    return {
        "release_sha": checkout_sha,
        "missing_checks": missing,
        "review_complete": len(missing) == 0,
        "upload_authorized": False,
        "reason": "External evidence requires independent human review; this module cannot start SFTP uploads.",
    }
