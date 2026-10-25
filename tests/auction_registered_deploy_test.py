#!/usr/bin/env python3
"""Regression checks for registered one-shot auction deployment evidence."""
import importlib.util
import json
from pathlib import Path

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("verified", root / "scripts/verified_deploy_controller.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
workflow, config = module.registered_release(
    "DEPLOY VERIFIED RELEASE\nworkflow=auction-organisation-release.yml\n"
    "release_sha=" + "a" * 40 + "\npreflight_run_id=123\n"
    "expected_helper=ABSENT\nexpected_page=ABSENT\nexpected_bootstrap=" + "b" * 64
)
assert workflow == "auction-organisation-release.yml"
run = {"id": 123, "head_sha": "a" * 40, "created_at": "2026-10-25T07:10:00Z",
       "path": ".github/workflows/registered-release-auto-readonly.yml",
       "status": "completed", "conclusion": "success"}
manifest = {"sha": "a" * 40, "run_id": "123", "remote": dict(zip(config["paths"],
            ("ABSENT", "ABSENT", "b"*64)))}
normalized = module.normalize_registered_manifest(manifest, run, workflow, config)
assert normalized["sha256"] == manifest["remote"]
try:
    module.normalize_registered_manifest(dict(manifest, sha="c"*40), run, workflow, config)
except ValueError:
    pass
else:
    raise AssertionError("Accepted unrelated release commit")
print("PASS: approved auction manifest, ABSENT-only allowlist, SHA identity")
