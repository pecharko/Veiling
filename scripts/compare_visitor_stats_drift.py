#!/usr/bin/env python3
"""T-063 offline Git history lookup for the observed T-062 production SHA-256s.

NO remote connection, no SQL, no production writes and no approval for deploy.
Observed hashes are transcribed from GitHub Actions run 39547454464.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
EVIDENCE_RUN = 39547454464
EVIDENCE_SHA = "e968945679802e67c485c36ad8c90f59694f9f3c"
OBSERVED = {
    "private/bootstrap.php": "e9f0b900b3bc6f58045a5cf4e464d9594854b94c94e1e31f74d728efd0955a9a",
    "catalogus-public/index.php": "d6f5bf173c3fcb419727c33caeaab0bc6e7b54d19e6f78d9b6f716794c78ae27",
    "catalogus-public/kavel.php": "9c375dbcc23b26ac2fa00338f369d085faff3a91f7de1f283808db5197761668",
}
ABSENT = ("private/visitor_stats.php", "catalogus-public/admin/statistieken.php")
MAX_HISTORY = 2000

def git(*args: str) -> bytes:
    p = subprocess.run(["git", *args], cwd=ROOT, capture_output=True, check=False)
    if p.returncode:
        raise RuntimeError("Git history lookup failed")
    return p.stdout

def compare_history(head_sha: str) -> dict:
    if not re.fullmatch("[0-9a-f]{40}", head_sha):
        raise ValueError("Full main SHA required")
    if git("rev-parse", "HEAD").decode().strip() != head_sha:
        raise ValueError("Checked out HEAD differs from approved inspection head")
    try:
        git("merge-base", "--is-ancestor", EVIDENCE_SHA, head_sha)
    except RuntimeError as exc:
        raise ValueError("T-062 inspected SHA not in current main ancestry") from exc
    entries = []
    for path, observed in OBSERVED.items():
        revs = git("rev-list", "--first-parent", "HEAD", "--", path).decode().splitlines()
        if len(revs) > MAX_HISTORY:
            raise ValueError("History exceeds safe offline inspection bound")
        matches = []
        for rev in revs:
            try:
                blob = git("show", rev + ":" + path)
            except RuntimeError:
                continue
            if hashlib.sha256(blob).hexdigest() == observed:
                matches.append(rev)
        entries.append({
            "path": path,
            "observed_remote_sha256": observed,
            "matching_historical_commits": matches[:20],
            "match_count": len(matches),
            "historical_match_found": bool(matches),
            "approved_production_baseline": False,
        })
    return {
        "format": "41lokeren-visitor-stats-t063-drift-evidence-v1",
        "report_source_run_id": EVIDENCE_RUN,
        "report_source_commit": EVIDENCE_SHA,
        "current_head": head_sha,
        "entries": entries,
        "new_remote_paths_absent_in_t062": list(ABSENT),
        "offline_only": True,
        "remote_rechecked": False,
        "production_baseline_verified": False,
        "release_approved": False,
        "deploy_approved": False,
        "next": "Review any mismatches and independent deploy provenance; re-run remote preflight before deployment.",
    }

def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--head-sha", required=True)
    args = parser.parse_args()
    try:
        print(json.dumps(compare_history(args.head_sha.lower()), indent=2, sort_keys=True))
        return 0
    except (RuntimeError, ValueError, OSError) as exc:
        print("T-063 STOP: " + str(exc), file=sys.stderr)
        return 1

if __name__ == "__main__":
    raise SystemExit(main())
