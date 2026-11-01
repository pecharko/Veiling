#!/usr/bin/env python3
"""Fail-closed, read-only release handoff decision engine.

Accepts trusted *normalized* facts, not GitHub comments as authority.
Never merges, dispatches, uploads, or authenticates an owner.
"""
import argparse
import json
import re
from pathlib import Path

SHA = re.compile(r"^[a-f0-9]{40}$")
RID = re.compile(r"^[a-z0-9][a-z0-9-]{2,60}$")
TRUE = True


def evaluate(state):
    if not isinstance(state, dict):
        raise ValueError("Expected JSON object")
    allowed = {
        "release_id", "pr_head", "main_sha", "pr_ci", "reviewed_manifest",
        "merge_authorized", "merged", "push_ci", "production_authorized",
        "authorization_sha", "dispatch_started", "deployment_verified",
        "browser_accepted", "independent_evidence_verified",
    }
    if set(state) - allowed:
        raise ValueError("Unexpected release state fields")
    rid = state.get("release_id")
    head = state.get("pr_head")
    main = state.get("main_sha")
    if not isinstance(rid, str) or not RID.fullmatch(rid):
        raise ValueError("Unsafe release id")
    if not isinstance(head, str) or not SHA.fullmatch(head):
        raise ValueError("Invalid PR head")
    if main is not None and (not isinstance(main, str) or not SHA.fullmatch(main)):
        raise ValueError("Invalid main SHA")
    flags = allowed - {"release_id", "pr_head", "main_sha", "authorization_sha"}
    for key in flags:
        if key in state and type(state[key]) is not bool:
            raise ValueError("Invalid boolean: " + key)
    approval_sha = state.get("authorization_sha")
    if approval_sha is not None and (not isinstance(approval_sha, str) or not SHA.fullmatch(approval_sha)):
        raise ValueError("Invalid approval SHA")
    def decision(action, reason):
        return {"release_id": rid, "action": action, "reason": reason, "mutates": False}
    if not state.get("reviewed_manifest") or not state.get("pr_ci"):
        return decision("PREPARE_AND_VERIFY", "Review single PR code, exact manifest and applicable CI")
    if not state.get("merged"):
        if not state.get("merge_authorized"):
            return decision("REQUEST_MERGE_AUTHORIZATION", "Immutable PR head and complete file scope must be approved")
        return decision("VERIFY_AND_MERGE_WITH_OWNER_GATE", "Owner approval exists; enforce exact-head, main drift and checks before merge")
    if not main:
        return decision("VERIFY_MAIN", "Merged main SHA missing")
    if not state.get("push_ci"):
        return decision("WAIT_FOR_MAIN_CI", "Do not request go or production approval while push CI is unverified")
    if not state.get("production_authorized"):
        return decision("REQUEST_PRODUCTION_AUTHORIZATION", "Owner must approve exact release manifest, immutable main SHA and remote baselines")
    if approval_sha != main:
        return decision("STOP_AUTHORIZATION_DRIFT", "Authorization does not bind current immutable main SHA")
    if not state.get("dispatch_started"):
        return decision("OWNER_DISPATCH_REQUIRED", "Only existing owner-authenticated controller or manual GitHub Actions start may dispatch")
    if not state.get("deployment_verified"):
        return decision("VERIFY_DEPLOYMENT", "Read actual release job, per-file VERIFIED readbacks and rollback artifacts; no automatic retry")
    if not state.get("browser_accepted"):
        return decision("REQUEST_BROWSER_ACCEPTANCE", "Technical verification is not independent browser acceptance")
    if not state.get("independent_evidence_verified"):
        return decision("EVIDENCE_REVIEW_OPEN", "Keep independent OVH readback and rollback provenance gaps visible; never claim fully accepted")
    return decision("COMPLETE", "Owner browser acceptance and independent technical evidence verified")


def main():
    parser = argparse.ArgumentParser(description="Offline release handoff advice (read-only)")
    parser.add_argument("facts", type=Path, help="Locally prepared normalized release state JSON")
    args = parser.parse_args()
    print(json.dumps(evaluate(json.loads(args.facts.read_text(encoding="utf-8"))), indent=2))


if __name__ == "__main__":
    main()
