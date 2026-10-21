#!/usr/bin/env python3
"""Offline six-file statistics release manifest. No network, database, SFTP or mutation."""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FILES = (
    "catalogus-public/privacy.php",
    "private/visitor_stats.php",
    "catalogus-public/admin/statistieken.php",
    "private/bootstrap.php",
    "catalogus-public/index.php",
    "catalogus-public/kavel.php",
)
MARKERS = {
    "private/visitor_stats.php": ("function track_catalog_view(", "analytics_secret", "visitor_stats_daily"),
    "catalogus-public/admin/statistieken.php": ("require_auth();", "visitor_stats_daily", "admin_top("),
    "private/bootstrap.php": ("admin/statistieken.php", "function require_auth()"),
    "catalogus-public/index.php": ("private/visitor_stats.php", "track_catalog_view();", "url('privacy.php')"),
    "catalogus-public/kavel.php": ("private/visitor_stats.php", "track_catalog_view((int)$lot['id']);", "url('privacy.php')"),
    "catalogus-public/privacy.php": ("Privacy en bezoekersstatistieken", "Verantwoordelijke organisatie:</strong> 41 Lokeren", "tot het einde van de veiling in 2027 en daarna verwijderd", "rechtsgrond en de proportionaliteit van de bewaartermijn", "mailto:41clublokeren@gmail.com"),
}

def git(*arguments: str) -> bytes:
    result = subprocess.run(["git", *arguments], cwd=ROOT, capture_output=True, check=False)
    if result.returncode:
        raise ValueError("Git verification failed")
    return result.stdout

def plan(sha: str) -> dict:
    if not re.fullmatch(r"[a-f0-9]{40}", sha):
        raise ValueError("Expected exact 40-hex commit SHA")
    if git("rev-parse", "HEAD").decode().strip() != sha:
        raise ValueError("Release SHA does not match checked-out HEAD")
    if len(FILES) != 6 or len(set(FILES)) != 6 or set(FILES) != set(MARKERS):
        raise ValueError("Fixed allowlist invariant violated")
    entries = []
    for path in FILES:
        if not path.endswith(".php") or path.startswith("/") or ".." in path.split("/"):
            raise ValueError("Unsafe path")
        source = ROOT / path
        if source.is_symlink() or not source.is_file():
            raise ValueError("Missing or symlinked source: " + path)
        raw = source.read_bytes()
        if not raw or len(raw) > 1024 * 1024:
            raise ValueError("Invalid source size: " + path)
        if git("show", sha + ":" + path) != raw:
            raise ValueError("Source does not match pinned commit: " + path)
        if any(marker.encode() not in raw for marker in MARKERS[path]):
            raise ValueError("Required integration missing: " + path)
        lint = subprocess.run(["php", "-l", str(source)], cwd=ROOT, capture_output=True, check=False)
        if lint.returncode:
            raise ValueError("PHP lint failed: " + path)
        entries.append({"path": path, "bytes": len(raw), "sha256": hashlib.sha256(raw).hexdigest(), "php_lint": "pass"})
    return {
        "format": "41lokeren-visitor-stats-six-offline-plan-v1",
        "release_sha": sha,
        "files": entries,
        "allowlist_count": 6,
        "schema_migration": "database/visitor_stats.sql (separate manual approval)",
        "analytics_secret": "manual private configuration; never committed",
        "upload_allowed": False,
        "database_mutation_allowed": False,
        "remote_drift_checked": False,
        "hostkey_checked": False,
        "rollback_backup_checked": False,
        "production_verified": False,
    }

def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--release-sha", required=True)
    args = parser.parse_args()
    try:
        print(json.dumps(plan(args.release_sha.lower()), indent=2, sort_keys=True))
        return 0
    except (ValueError, OSError) as exc:
        print("OFFLINE PLAN STOP: " + str(exc), file=sys.stderr)
        return 1

if __name__ == "__main__":
    raise SystemExit(main())
