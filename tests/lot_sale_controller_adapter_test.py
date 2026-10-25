#!/usr/bin/env python3
"""Offline no-rollback adapter contract: no GitHub issues, SFTP or dispatch."""
import importlib.util
from datetime import datetime, timezone
from pathlib import Path
from unittest.mock import patch

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("adapter", root / "scripts/lot_sale_controller_adapter.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
sha = "a" * 40
now = datetime.now(timezone.utc).isoformat()
text = "\n".join([
    module.PREFIX, "release_sha=" + sha, "plan_run_id=123",
    "schema_ready=SCHEMA VERIFIED", "risk_acceptance=" + module.RISK,
])
data = module.parse_approval(text)
run = {"id": 123, "head_sha": sha, "status": "completed", "conclusion": "success",
       "head_branch": "main", "path": module.PLAN,
       "event": "workflow_dispatch", "created_at": now}
manifest = {"release_sha": sha, "files": {
    p: {"remote_sha256": "b" * 64, "source_sha256": "c" * 64} for p in module.PATHS
}}
kwargs = {"job_names": ["plan"], "artifact_name": "lot-sale-three-preflight-123"}
assert module.verify_plan(data, run, manifest, sha, **kwargs) == data
for bad in (
    text.replace("SCHEMA VERIFIED", "SCHEMA UNKNOWN"),
    text.replace(module.RISK, "I accept the risk"),
    text.replace("plan_run_id=123", "plan_run_id=0"),
):
    try:
        module.parse_approval(bad)
    except ValueError:
        pass
    else:
        raise AssertionError("Unsafe approval accepted")
for bad_run, bad_manifest, bad_sha, bad_kwargs in (
    ({**run, "event": "push"}, manifest, sha, kwargs),
    ({**run, "head_sha": "d" * 40}, manifest, sha, kwargs),
    ({**run, "created_at": "2020-01-01T00:00:00Z"}, manifest, sha, kwargs),
    (run, {**manifest, "files": {}}, sha, kwargs),
    (run, manifest, "f" * 40, kwargs),
    (run, manifest, sha, {**kwargs, "job_names": []}),
    (run, manifest, sha, {**kwargs, "artifact_name": "wrong"}),
):
    try:
        module.verify_plan(data, bad_run, bad_manifest, bad_sha, **bad_kwargs)
    except ValueError:
        pass
    else:
        raise AssertionError("Unsafe plan accepted")
print("PASS no rollback adapter: schema, risk, plan identity and hashes; no dispatch")
