#!/usr/bin/env python3
"""Offline manifest builder from independently authenticated VERIFIED upload readbacks.

The operator must authenticate the GitHub workflow run, exact path, SHA and
subsequent release history separately. This tool does not attest provenance.
"""
import argparse
import hashlib
import json
import re
from pathlib import Path
from scripts.shared_ovh_readonly_baselines import validate_paths

VERIFIED = re.compile(r"^VERIFIED (\S+) ([0-9a-f]{64})$")
def verified_sha(log_text: str, path: str) -> str:
    matches = []
    for line in log_text.splitlines():
        # GitHub Actions log lines include a timestamp preceding the verifier.
        payload = line.split("Z ", 1)[-1] if "Z " in line else line
        match = VERIFIED.fullmatch(payload.strip())
        if match and match.group(1) == path:
            matches.append(match.group(2))
    if len(matches) != 1:
        raise ValueError("Missing or ambiguous VERIFIED readback")
    return matches[0]

def from_verified_log(root: Path, paths: list[str], text: str) -> dict:
    if paths != validate_paths(",".join(paths)):
        raise ValueError("Invalid or duplicate paths")
    entries = []
    for path in paths:
        source = root / path
        if not source.is_file() or source.is_symlink() or source.stat().st_size > 8 * 1024 * 1024:
            raise ValueError("Missing or unsafe source")
        entries.append({"path": path,
            "source_sha256": hashlib.sha256(source.read_bytes()).hexdigest(),
            "remote_sha256": verified_sha(text, path)})
    return {"schema": 1, "files": entries}

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--source-root", type=Path, required=True)
    parser.add_argument("--verified-job-log", type=Path, required=True)
    parser.add_argument("--paths", required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    result = from_verified_log(args.source_root, args.paths.split(","),
                               args.verified_job_log.read_text(encoding="utf-8"))
    args.output.write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")

if __name__ == "__main__":
    main()
