#!/usr/bin/env python3
"""Offline validation tests. No GitHub or production access."""
import importlib.util
from pathlib import Path
from datetime import datetime, timezone

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("controller", root / "scripts/verified_deploy_controller.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
now = datetime.now(timezone.utc).isoformat()
sha = "a" * 40
run = {"id": 123, "head_sha": sha, "path": module.PREFLIGHT,
       "conclusion": "success", "status": "completed", "created_at": now}
hashes = dict.fromkeys(module.PATHS, "b" * 64)
manifest = {"schema": 1, "run_id": 123, "head_sha": sha, "captured_at": now, "sha256": hashes}
approval = module.PREFIX + "\n" + "\n".join([
    "release_sha=" + sha, "preflight_run_id=123",
    *[k + "=" + "b" * 64 for k in module.FIELDS[2:]],
])
assert module.validate(approval, manifest, run, sha)["preflight_run_id"] == "123"
def blocked(name, text=approval, evidence=manifest, result=run, head=sha):
    try:
        module.validate(text, evidence, result, head)
    except (ValueError, KeyError, TypeError):
        return
    raise AssertionError("Unsafe approval accepted: " + name)
blocked("wrong SHA", head="c" * 40)
blocked("bad approval", text=approval.replace("b" * 64, "c" * 64, 1))
blocked("wrong run id", evidence={**manifest, "run_id": 124})
blocked("wrong path", result={**run, "path": "other.yml"})
blocked("wrong status", result={**run, "conclusion": "failure"})
blocked("unexpected manifest field", evidence={**manifest, "extra": True})
blocked("duplicate field", text=approval + "\nextra=x")
source = (root / "scripts/verified_deploy_controller.py").read_text(encoding="utf-8")
assert '"gh", "run", "download"' in source
assert 'if fresh["state"] != "open":' in source
assert source.index('state=closed') < source.index('dispatch_approved(command)')
assert 'github.rest.actions.createWorkflowDispatch' not in source
from unittest.mock import patch
from subprocess import CompletedProcess
with patch.object(module.subprocess, "run", return_value=CompletedProcess([], 0, "opened\nclosed\nreopened\n", "")):
    assert module.has_closed_event("example/repo", 123)
with patch.object(module.subprocess, "run", return_value=CompletedProcess([], 0, "opened\nlabeled\n", "")):
    assert not module.has_closed_event("example/repo", 123)
assert '"--paginate", "--jq", ".[].event"' in source
assert 'CONTROLLER_DRY_RUN' in source
assert source.index('CONTROLLER_DRY_RUN') < source.index('state=closed')
assert 'except subprocess.CalledProcessError as exc' in source
from unittest.mock import patch as mock_patch
import subprocess
with mock_patch.object(module.subprocess, "run", side_effect=subprocess.CalledProcessError(1, ["gh", "workflow", "run"])):
    try:
        module.dispatch_approved(("gh", "workflow", "run"))
    except RuntimeError as exc:
        assert "Do not reopen issue" in str(exc)
    else:
        raise AssertionError("Failed dispatch did not stop")
with mock_patch.object(module.subprocess, "run", return_value=subprocess.CompletedProcess([], 0)):
    module.dispatch_approved(("gh", "workflow", "run"))
assert 'Do not reopen issue' in source
workflow = (root / ".github/workflows/verified-deploy-controller.yml").read_text(encoding="utf-8")
assert "issues: write" in workflow and "actions: write" in workflow
assert 'CONTROLLER_DRY_RUN: "false"' in workflow
assert "persist-credentials: false" in workflow
print("PASS verified deploy controller offline validation and one-time dispatch contracts")
