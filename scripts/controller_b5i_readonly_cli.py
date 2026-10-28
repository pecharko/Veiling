#!/usr/bin/env python3
"""Opt-in B5i GitHub Actions:read evidence CLI; no dispatch or OVH connection."""
import argparse
import json
import os
from pathlib import Path

from controller_b5c_github_evidence import collect_full_b5_report

MAX_INPUT = 1024 * 1024
INPUT_FIELDS = {"release_id", "release_sha", "release_run_id", "expected_paths",
                "authorized_main_sha", "observation", "b4_run_id",
                "b4_execution_sha"}


def report_from_snapshot(snapshot, token, *, collect_fn=collect_full_b5_report):
    if not isinstance(snapshot, dict) or set(snapshot) != INPUT_FIELDS:
        raise ValueError("Exact B5i pinned snapshot fields required")
    return collect_fn(**snapshot, token=token)


def main(argv=None, *, collect_fn=collect_full_b5_report):
    parser = argparse.ArgumentParser(description="Read-only GitHub evidence reporting; no dispatch")
    parser.add_argument("--input", required=True, help="Existing local immutable pins and B4 observation")
    parser.add_argument("--output", required=True, help="Local JSON output")
    args = parser.parse_args(argv)
    token = os.environ.get("GITHUB_TOKEN")
    if not token:
        parser.error("GITHUB_TOKEN with Actions:read required")
    src = Path(args.input)
    if not src.is_file() or src.stat().st_size > MAX_INPUT:
        parser.error("Missing or oversized local evidence input")
    snapshot = json.loads(src.read_text(encoding="utf-8"))
    report = report_from_snapshot(snapshot, token, collect_fn=collect_fn)
    Path(args.output).write_text(json.dumps(report, indent=2, sort_keys=True) + "\n",
                                 encoding="utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
