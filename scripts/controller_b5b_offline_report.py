#!/usr/bin/env python3
"""B5b offline reporting from caller-supplied JSON snapshots only.

No network, GitHub API, OVH access, deployment, approval or dispatch.
"""
import argparse
import json
from pathlib import Path
from controller_readonly_reconciliation import reconcile_independent_b4


def render(payload):
    if not isinstance(payload, dict) or set(payload) != {
            "base_report", "observation", "execution_run", "release_id",
            "release_sha", "release_run_id", "expected_paths", "authorized_main_sha"}:
        raise ValueError("Exact B5b offline snapshot contract required")
    result = reconcile_independent_b4(
        payload["base_report"], payload["observation"],
        release_id=payload["release_id"], release_sha=payload["release_sha"],
        run_id=payload["release_run_id"], expected_files=payload["expected_paths"],
        execution_run=payload["execution_run"],
        authorized_main_sha=payload["authorized_main_sha"])
    return {"schema": 1, "source": "untrusted_offline_snapshot_consistency",
            "status": result["status"], "reason": result["reason"],
            "evidence_scope": "offline_consistency_only",
            "independent_provenance_authenticated": False,
            "browser_acceptance": "PENDING", "fully_accepted": False,
            "deployment_authorized": False, "dispatch_started": False,
            "release_id": result["release_id"],
            "release_sha": result["release_sha"],
            "release_run_id": result["release_run_id"],
            "expected_paths": result["expected_paths"],
            "observed_main_sha": result.get("observed_main_sha")}


def main():
    parser = argparse.ArgumentParser(description="Offline B5b consistency report")
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    source = Path(args.input)
    if not source.is_file() or source.stat().st_size > 1024 * 1024:
        parser.error("Missing or oversized local snapshot")
    report = render(json.loads(source.read_text(encoding="utf-8")))
    output = Path(args.output)
    output.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return 0  # INCOMPLETE/MISMATCH are evidence outcomes, not authorization


if __name__ == "__main__":
    raise SystemExit(main())
