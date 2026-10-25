#!/usr/bin/env python3
"""Fail-closed static compatibility checks for registered production release workflows.

This is a contract audit, NOT an authorization, preflight or deployment.
"""
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
registry = json.loads((ROOT / "scripts/verified_deploy_registry.json").read_text())
assert registry["schema"] == 1 and set(registry) == {"schema", "releases"}
assert registry["releases"], "No registered workflows"

for name, config in registry["releases"].items():
    assert re.fullmatch(r"[a-zA-Z0-9_-]+\.yml", name), name
    release = ROOT / ".github/workflows" / name
    preflight = ROOT / config["preflight"]
    assert release.is_file() and preflight.is_file(), name
    release_source = release.read_text()
    preflight_source = preflight.read_text()
    assert re.search(r"(?m)^  workflow_dispatch:", release_source), name
    assert re.search(r"(?m)^    if: github.ref == 'refs/heads/main'", release_source), name
    assert "mode=deploy" not in preflight_source and "workflow_dispatch:" in release_source
    assert "actions/upload-artifact@v4" in preflight_source, name
    assert config["artifact_prefix"] in preflight_source, name
    assert len(config["paths"]) == len(config["hash_inputs"]) > 0, name
    assert len(set(config["paths"])) == len(config["paths"]), name
    assert len(set(config["hash_inputs"])) == len(config["hash_inputs"]), name
    for field in ("mode", "release_sha", "confirmation", *config["hash_inputs"]):
        assert re.search(r"(?m)^      " + re.escape(field) + r":\s*$", release_source), (name, field)
    assert config["confirmation"] in release_source, name
    assert re.search(r"(?m)^\s+if: inputs\.mode == 'deploy'", release_source), name
    assert "environment: production" in release_source, name
    assert "preflight-sha256.json" in preflight_source, name
    assert "persist-credentials: false" in release_source, name
    for path in config["paths"]:
        assert (ROOT / path).is_file(), (name, path)
    print("PASS registered real release compatibility:", name)

print("PASS all registered release contracts: no deployment performed")
