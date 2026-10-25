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
# Inspect the call inside main(), not the earlier function definition.
main_body = source.split("def main():", 1)[1].split('if __name__ == "__main__":', 1)[0]
assert main_body.count("dispatch_approved(command)") == 1
assert main_body.index('state=closed') < main_body.index('dispatch_approved(command)')
assert main_body.index('if fresh["state"] != "open":') < main_body.index('state=closed')
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

# Runtime regression: a valid dry-run must return before any issue write or dispatch.
import os
from unittest.mock import patch as secure_patch
issue = {"state": "open", "user": {"login": "pecharko"},
         "title": "DEPLOY-AUTH: test",
         "labels": [{"name": "approved-deploy-request"}]}
artifact = {"id": 456, "name": "kavel-preflight-sha256-123", "expired": False}
def fake_gh(endpoint, *args, **kwargs):
    if args or endpoint.endswith("/issues/77") and kwargs:
        raise AssertionError("Unexpected GitHub mutation")
    if endpoint.endswith("/issues/77"):
        return issue
    if endpoint.endswith("/branches/main"):
        return {"commit": {"sha": sha}}
    if endpoint.endswith("/actions/runs/123"):
        return run
    if endpoint.endswith("/actions/runs/123/artifacts"):
        return {"artifacts": [artifact]}
    raise AssertionError("Unexpected GitHub API request: " + endpoint)
with secure_patch.dict(os.environ, {
    "REPOSITORY": "pecharko/Veiling", "ISSUE_NUMBER": "77",
    "ACTOR": "pecharko", "APPROVAL_TEXT": approval,
    "CONTROLLER_DRY_RUN": "true"
}), secure_patch.object(module, "gh", side_effect=fake_gh), \
     secure_patch.object(module, "has_closed_event", return_value=False), \
     secure_patch.object(module, "download_artifact", return_value=__import__("json").dumps(manifest).encode()), \
     secure_patch.object(module, "dispatch_approved", side_effect=AssertionError("Dispatched during dry-run")):
    module.main()
print("PASS valid dry-run returns without issue write or deployment dispatch")

# Registered-format tests: exact workflow, SHA, hashes and artifact remain mandatory.
import json
with secure_patch.object(module, "REGISTRY_PATH", str(root / "scripts/verified_deploy_registry.json")):
    registered_text = ("DEPLOY VERIFIED RELEASE\n"
                       "workflow=kavelnummer-thumbnails-release.yml\n"
                       "release_sha=" + sha + "\npreflight_run_id=123\n" +
                       "\n".join(k + "=" + "b" * 64 for k in
                                  ["expected_kavel", "expected_lot_form", "expected_css", "expected_index"]))
    name, config = module.registered_release(registered_text)
    assert name == "kavelnummer-thumbnails-release.yml"
    assert module.validate_registered(registered_text, manifest, run, sha, name, config)["release_sha"] == sha
    for broken in [registered_text.replace("expected_css=", "expected_unknown="),
                   registered_text.replace("workflow=kavelnummer-thumbnails-release.yml", "workflow=evil.yml")]:
        try:
            name2, cfg2 = module.registered_release(broken)
            module.validate_registered(broken, manifest, run, sha, name2, cfg2)
        except (ValueError, KeyError):
            pass
        else:
            raise AssertionError("Unsafe generic release accepted")
    for label, payload, evidence, result, head in [
        ("wrong SHA", registered_text, manifest, run, "c" * 40),
        ("wrong digest", registered_text.replace("expected_css=" + "b" * 64, "expected_css=" + "c" * 64), manifest, run, sha),
        ("wrong preflight path", registered_text, manifest, {**run, "path": "other.yml"}, sha),
        ("wrong manifest hash", registered_text, {**manifest, "sha256": {}}, run, sha),
        ("expired preflight", registered_text, manifest, {**run, "created_at": "2020-01-01T00:00:00Z"}, sha),
    ]:
        try:
            module.validate_registered(payload, evidence, result, head, name, config)
        except (ValueError, KeyError):
            pass
        else:
            raise AssertionError("Unsafe registered approval accepted: " + label)
print("PASS registered workflow validation")
