#!/usr/bin/env python3
"""Prepare a reviewed release manifest from an exact-PR-head read-only OVH scan.

Offline only. This tool never connects to OVH or writes to production.
"""
import argparse
import hashlib
import json
from pathlib import Path
from scripts.shared_ovh_readonly_baselines import validate_paths

def build_manifest(source_root: Path, evidence: dict, head: str, paths: list[str]) -> dict:
    if not isinstance(head, str) or len(head) != 40 or any(c not in "0123456789abcdef" for c in head):
        raise ValueError("Invalid immutable PR head")
    if evidence.get("schema") != 1 or evidence.get("head_sha") != head:
        raise ValueError("Evidence head/schema mismatch")
    if paths != validate_paths(",".join(paths)):
        raise ValueError("Invalid paths")
    records = evidence.get("files")
    if not isinstance(records, list) or len(records) != len(paths):
        raise ValueError("Evidence files mismatch")
    results = []
    for expected, item in zip(paths, records):
        if not isinstance(item, dict) or item.get("path") != expected:
            raise ValueError("Evidence order/path mismatch")
        source = source_root / expected
        if not source.is_file() or source.is_symlink() or source.stat().st_size > 8 * 1024 * 1024:
            raise ValueError("Unsafe source")
        digest = hashlib.sha256(source.read_bytes()).hexdigest()
        remote = item.get("remote_sha256")
        if not (remote == "ABSENT" or
                isinstance(remote, str) and len(remote) == 64 and
                all(c in "0123456789abcdef" for c in remote)):
            raise ValueError("Invalid remote hash")
        if item.get("source_sha256") != digest:
            raise ValueError("Source mismatch: restart read-only baseline")
        results.append({"path": expected, "source_sha256": digest, "remote_sha256": remote})
    return {"schema": 1, "files": results}

def main():
    p = argparse.ArgumentParser()
    p.add_argument("--source-root", type=Path, required=True)
    p.add_argument("--evidence", type=Path, required=True)
    p.add_argument("--head", required=True)
    p.add_argument("--paths", required=True, help="comma-delimited approved paths")
    p.add_argument("--output", type=Path, required=True)
    a = p.parse_args()
    evidence = json.loads(a.evidence.read_text(encoding="utf-8"))
    result = build_manifest(a.source_root, evidence, a.head, a.paths.split(","))
    a.output.write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")

if __name__ == "__main__":
    main()
