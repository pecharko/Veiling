#!/usr/bin/env python3
"""Fully mocked 3-file authorization lifecycle: no real writes or dispatch."""
import importlib.util
import os
from pathlib import Path
from datetime import datetime, timezone
from unittest.mock import patch

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("verified", root / "scripts/verified_deploy_controller.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
sha, run_id, issue_no = "a" * 40, 789, "77"
adapter = __import__("lot_sale_controller_adapter")
approval = "\n".join([
    adapter.PREFIX, "release_sha=" + sha, "plan_run_id=789",
    "schema_ready=SCHEMA VERIFIED", "risk_acceptance=" + adapter.RISK])
issue = {"state": "open", "user": {"login": "pecharko"}, "title": "DEPLOY-AUTH: scoped test",
         "labels": [{"name": "approved-deploy-request"}]}
run = {"id": run_id, "head_sha": sha, "head_branch": "main", "status": "completed",
       "conclusion": "success", "path": adapter.PLAN, "event": "workflow_dispatch",
       "created_at": datetime.now(timezone.utc).isoformat()}
manifest = {"release_sha": sha, "files": {
    path: {"remote_sha256": "b" * 64, "source_sha256": "c" * 64}
    for path in adapter.PATHS}}
artifact = {"id": 456, "name": "lot-sale-three-preflight-789", "expired": False}
calls = []
closed = False

def gh(endpoint, *args):
    global closed
    calls.append((endpoint, args))
    if endpoint.endswith("/issues/77"):
        if args:
            assert args == ("-X", "PATCH", "-f", "state=closed", "-f", "state_reason=completed")
            closed = True
            issue["state"] = "closed"
        return issue.copy()
    if endpoint.endswith("/branches/main"):
        return {"commit": {"sha": sha}}
    if endpoint.endswith("/actions/runs/789"):
        return run
    if endpoint.endswith("/actions/runs/789/jobs"):
        return {"jobs": [{"name": "plan", "conclusion": "success"}]}
    if endpoint.endswith("/actions/runs/789/artifacts"):
        return {"artifacts": [artifact]}
    raise AssertionError("Unexpected API endpoint: " + endpoint)

def blocked_mutation(*args, **kwargs):
    raise AssertionError("Dry-run attempted state mutation or deployment")

env = {"REPOSITORY": "pecharko/Veiling", "ISSUE_NUMBER": issue_no,
       "ACTOR": "pecharko", "APPROVAL_TEXT": approval, "CONTROLLER_DRY_RUN": "true"}
with patch.dict(os.environ, env), patch.object(module, "gh", side_effect=gh), \
     patch.object(module, "has_closed_event", return_value=False), \
     patch.object(module, "download_lot_sale_artifact", return_value=manifest), \
     patch.object(module, "dispatch_approved", side_effect=blocked_mutation):
    module.main()
assert not closed and issue["state"] == "open"
assert not any(args for _, args in calls)
print("PASS true dry-run: complete issue and plan validation, zero mutations")

# Simulate approval consumption and capture the target invocation rather than running it.
commands = []
calls.clear()
with patch.dict(os.environ, {**env, "CONTROLLER_DRY_RUN": "false"}), \
     patch.object(module, "gh", side_effect=gh), \
     patch.object(module, "has_closed_event", return_value=False), \
     patch.object(module, "download_lot_sale_artifact", return_value=manifest), \
     patch.object(module, "dispatch_approved", side_effect=commands.append):
    module.main()
assert closed and issue["state"] == "closed" and len(commands) == 1
expected = ("gh", "workflow", "run", "lot-sale-three-simple-release.yml",
            "--repo", "pecharko/Veiling", "--ref", "main",
            "-f", "mode=deploy", "-f", "release_sha=" + sha,
            "-f", "confirmation=DEPLOY LOT SALE THREE NO ROLLBACK",
            "-f", "plan_run_id=789", "-f", "schema_ready=SCHEMA VERIFIED")
assert commands[0] == expected, (commands[0], expected)
assert any(args and args[0] == "-X" for _, args in calls)
print("PASS simulated one-shot issue consumption and exact workflow input contract")

# Replaying the same authorization is rejected before any API writes/dispatches.
calls.clear()
with patch.dict(os.environ, {**env, "CONTROLLER_DRY_RUN": "false"}), \
     patch.object(module, "gh", side_effect=gh), \
     patch.object(module, "has_closed_event", return_value=True), \
     patch.object(module, "dispatch_approved", side_effect=blocked_mutation):
    try:
        module.main()
    except ValueError:
        pass
    else:
        raise AssertionError("Replay succeeded")
assert not any(args for _, args in calls)
assert len(commands) == 1
print("PASS replay refused without additional workflow execution")
