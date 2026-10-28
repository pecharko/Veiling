#!/usr/bin/env python3
"""Offline release routing advisory; never changes release gates or deploys.

Use before creating release-requests/*.json. The shared release allowlist
remains authoritative and unchanged.
"""
import json
import sys

from scripts.shared_ovh_readonly_baselines import validate_paths

SPECIAL = {
    "private/visitor_stats_daily_cron.php": "issue224-cron-onefile",
}
MAX_PATHS = 25


def classify(paths):
    if not isinstance(paths, list) or not 1 <= len(paths) <= MAX_PATHS:
        raise ValueError("Expected 1-25 paths")
    if any(not isinstance(p, str) or not p or p != p.strip() for p in paths):
        raise ValueError("Invalid path")
    if len(set(paths)) != len(paths):
        raise ValueError("Duplicate path")
    shared, protected = [], {}
    for path in paths:
        if path in SPECIAL:
            protected.setdefault(SPECIAL[path], []).append(path)
            continue
        try:
            if validate_paths(path) != [path]:
                raise ValueError("Unrecognized shared release path")
        except ValueError as exc:
            raise ValueError("No approved release route for " + path) from exc
        shared.append(path)
    return {"shared_ovh": shared, "special": protected,
            "requires_separate_release": bool(shared and protected) or len(protected) > 1}


def main():
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python3 -m scripts.release_path_routing <paths-json>")
    paths = json.loads(sys.argv[1])
    print(json.dumps(classify(paths), indent=2))


if __name__ == "__main__":
    main()
