#!/usr/bin/env python3
"""Offline contract for the read-only preflight artifact; never contacts production."""
from pathlib import Path

repo = Path(__file__).resolve().parents[1]
source = (repo / "scripts/deploy_lot_number_thumbnail.py").read_text(encoding="utf-8")
workflow = (repo / ".github/workflows/kavel-release-readonly-preflight.yml").read_text(encoding="utf-8")

checks = {
    "sha256 generated from remote bytes": "hashes = {name: digest(data) for name, data in current.items()}" in source,
    "manifest opt-in during preflight": 'os.environ.get("PREFLIGHT_MANIFEST_PATH", "")' in source,
    "run-id bound": '"run_id": int(run_id)' in source,
    "commit bound": '"head_sha": head_sha' in source,
    "manifest written only after remote fetch": source.index('current = fetch_remote(sftp, root)') < source.index('output.write_text('),
    "upload artifact": "uses: actions/upload-artifact@v4" in workflow,
    "short retention": "retention-days: 1" in workflow,
    "fails if absent": "if-no-files-found: error" in workflow,
    "no deploy action": "deploy_lot_number_thumbnail.py upload" not in workflow,
}
for name, passed in checks.items():
    assert passed, f"FAILED: {name}"
print("PASS preflight artifact offline contract")
