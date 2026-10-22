#!/usr/bin/env python3
"""Offline-only proposed simple-mode two-file archival code manifest."""
import argparse
import hashlib
import json
import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FILES = (
    "private/visitor_stats_archive_one_day_experimental.php",
    "catalogus-public/admin/statistieken.php",
)
MARKERS = {
    FILES[0]: ("function archive_visitor_stats_one_day(", "GET_LOCK(", "RELEASE_LOCK(", "20000"),
    FILES[1]: ("require_auth();", "check_csrf();", "archive_visitor_stats_one_day(", "analytics_archive_pending"),
}

def checked(command):
    return subprocess.check_output(command, cwd=ROOT)

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--release-sha", required=True)
    args = parser.parse_args()
    if not re.fullmatch(r"[a-f0-9]{40}", args.release_sha):
        raise SystemExit("STOP: expected exact SHA")
    if checked(["git", "rev-parse", "HEAD"]).decode().strip() != args.release_sha:
        raise SystemExit("STOP: checkout differs from pinned SHA")
    result = []
    for path in FILES:
        f = ROOT / path
        if f.is_symlink() or not f.is_file():
            raise SystemExit("STOP: missing or symlinked source: " + path)
        data = f.read_bytes()
        if not data or len(data) > 1024 * 1024:
            raise SystemExit("STOP: invalid file size")
        if checked(["git", "show", args.release_sha + ":" + path]) != data:
            raise SystemExit("STOP: source mismatch: " + path)
        if not all(m.encode() in data for m in MARKERS[path]):
            raise SystemExit("STOP: missing required marker: " + path)
        subprocess.run(["php", "-l", str(f)], cwd=ROOT, check=True, capture_output=True)
        result.append({"path": path, "bytes": len(data), "sha256": hashlib.sha256(data).hexdigest(), "php_lint": "pass"})
    print(json.dumps({
        "format": "41lokeren-one-day-archive-simple-offline-v1",
        "release_sha": args.release_sha,
        "profile": "simple_without_rollback_provisional",
        "files": result,
        "allowlist_count": len(result),
        "upload_allowed": False,
        "database_mutation_allowed": False,
        "production_verified": False,
        "remote_drift_checked": False,
        "hostkey_checked": False,
        "separate_release_and_deploy_approval_required": True,
        "dependencies_to_confirm": ["existing visitor_stats_daily, visitor_stats_day_totals, visitor_stats_lot_totals schema"]
    }, indent=2, sort_keys=True))

if __name__ == "__main__":
    main()
