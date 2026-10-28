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
     secure_patch.object(module, "reject_prior_approved_release") as replay_check, \
     secure_patch.object(module, "dispatch_approved", side_effect=AssertionError("Dispatched during dry-run")):
    module.main()
    replay_check.assert_called_once_with("pecharko/Veiling", "77", module.WORKFLOW, sha)
print("PASS valid dry-run returns without issue write or deployment dispatch")

# B3.4: an already consumed release or unavailable history must fail dry-run
# before issue mutation, and must not dispatch. All operations are mocked.
for failure in (ValueError("Release already authorized in a prior consumed issue"),
                RuntimeError("GitHub unavailable")):
    with secure_patch.dict(os.environ, {
        "REPOSITORY": "pecharko/Veiling", "ISSUE_NUMBER": "77",
        "ACTOR": "pecharko", "APPROVAL_TEXT": approval,
        "CONTROLLER_DRY_RUN": "true"
    }), secure_patch.object(module, "gh", side_effect=fake_gh), \
         secure_patch.object(module, "has_closed_event", return_value=False), \
         secure_patch.object(module, "download_artifact", return_value=__import__("json").dumps(manifest).encode()), \
         secure_patch.object(module, "reject_prior_approved_release", side_effect=failure) as replay_check, \
         secure_patch.object(module, "dispatch_approved", side_effect=AssertionError("Dry-run dispatched")):
        try:
            module.main()
        except type(failure):
            pass
        else:
            raise AssertionError("Dry-run accepted unsafe replay history")
        replay_check.assert_called_once()
assert main_body.index("reject_prior_approved_release(repo, issue_number, target_workflow_identity") < main_body.index('if os.environ.get("CONTROLLER_DRY_RUN"')
print("PASS B3.4 dry-run replay and GitHub-history errors fail closed")


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


# Independent synthetic second release demonstrates the registry is genuinely generic.
# Never dispatch; all evidence is local and mocked.
from tempfile import TemporaryDirectory
from pathlib import Path as TestPath
with TemporaryDirectory() as folder:
    second = {
        "preflight": ".github/workflows/example-second-readonly-preflight.yml",
        "artifact_prefix": "example-second-proof",
        "confirmation": "DEPLOY EXAMPLE SECOND",
        "paths": ["catalogus-public/admin/example.php", "private/example_helper.php"],
        "hash_inputs": ["expected_example", "expected_helper"],
    }
    second_name = "example-second-release.yml"
    registry = {"schema": 1, "releases": {second_name: second}}
    registry_path = TestPath(folder) / "registry.json"
    registry_path.write_text(json.dumps(registry))
    second_approval = "\n".join([
        "DEPLOY VERIFIED RELEASE",
        "workflow=" + second_name,
        "release_sha=" + sha,
        "preflight_run_id=789",
        "expected_example=" + "1" * 64,
        "expected_helper=" + "2" * 64,
    ])
    second_run = {"id": 789, "head_sha": sha, "path": second["preflight"],
                  "conclusion": "success", "status": "completed", "created_at": now}
    second_manifest = {"schema": 1, "run_id": 789, "head_sha": sha,
                       "captured_at": now, "sha256": dict(zip(second["paths"], ["1" * 64, "2" * 64]))}
    with secure_patch.object(module, "REGISTRY_PATH", str(registry_path)):
        got_name, got = module.registered_release(second_approval)
        assert got_name == second_name and got == second
        assert module.validate_registered(second_approval, second_manifest, second_run, sha,
                                          got_name, got)["preflight_run_id"] == "789"
        for bad_text, bad_manifest, bad_run, bad_head in [
            (second_approval.replace("workflow=" + second_name, "workflow=not-registered.yml"), second_manifest, second_run, sha),
            (second_approval.replace("expected_helper=" + "2" * 64, "expected_helper=" + "3" * 64), second_manifest, second_run, sha),
            (second_approval, {**second_manifest, "sha256": {}}, second_run, sha),
            (second_approval, second_manifest, {**second_run, "path": "other.yml"}, sha),
            (second_approval, second_manifest, second_run, "f" * 40),
        ]:
            try:
                bad_name, bad_config = module.registered_release(bad_text)
                module.validate_registered(bad_text, bad_manifest, bad_run, bad_head,
                                           bad_name, bad_config)
            except (ValueError, KeyError):
                pass
            else:
                raise AssertionError("Second release accepted unsafe evidence")

# B3.3 GitHub-native cross-issue replay guard; all calls mocked, no API writes.
from unittest.mock import patch as cross_patch
prior = {"number": 27, "state": "closed"}
approval_prior = "DEPLOY VERIFIED RELEASE\nworkflow=sample-release.yml\nrelease_sha=" + sha
def history(endpoint, *args, **kwargs):
    if endpoint.endswith("/issues?state=all&labels=approved-deploy-request&per_page=100"):
        return [prior]
    if endpoint.endswith("/issues/27/comments?per_page=100"):
        return [{"body": approval_prior}]
    raise AssertionError("Unexpected API: " + endpoint)
with cross_patch.object(module, "gh", side_effect=history), cross_patch.object(module, "has_closed_event", return_value=True):
    try:
        module.reject_prior_approved_release("pecharko/Veiling", "28", "sample-release.yml", sha)
    except ValueError as exc:
        assert "already authorized" in str(exc)
    else:
        raise AssertionError("Cross-issue replay accepted")
    module.reject_prior_approved_release("pecharko/Veiling", "28", "other-release.yml", sha)
    module.reject_prior_approved_release("pecharko/Veiling", "28", "sample-release.yml", "b" * 40)
with cross_patch.object(module, "gh", return_value=[prior]), cross_patch.object(module, "has_closed_event", return_value=False):
    module.reject_prior_approved_release("pecharko/Veiling", "28", "sample-release.yml", sha)
with cross_patch.object(module, "gh", return_value=[prior] * 100):
    try:
        module.reject_prior_approved_release("pecharko/Veiling", "28", "sample-release.yml", sha)
    except ValueError as exc:
        assert "incomplete" in str(exc)
    else:
        raise AssertionError("Unbounded issue scan accepted")
with cross_patch.object(module, "gh", side_effect=RuntimeError("GitHub unavailable")):
    try:
        module.reject_prior_approved_release("pecharko/Veiling", "28", "sample-release.yml", sha)
    except RuntimeError:
        pass
    else:
        raise AssertionError("Unavailable history allowed")
assert main_body.index("reject_prior_approved_release(") < main_body.index('state=closed')

print("PASS independent second workflow with negative safety cases; no dispatch")

# Run the shared-manifest authorization regression suite in the existing
# controller security CI entrypoint; it must not be silently skipped.
import unittest as _unittest
import sys as _sys
_sys.path.insert(0, str(root / "tests"))
from shared_manifest_controller_adapter_test import SharedManifestAdapterTests
_suite = _unittest.defaultTestLoader.loadTestsFromTestCase(SharedManifestAdapterTests)
_result = _unittest.TextTestRunner(verbosity=2).run(_suite)
assert _result.wasSuccessful(), "Shared manifest approval security tests failed"
