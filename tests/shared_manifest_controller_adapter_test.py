"""Offline release-controller safety regression tests. No external calls."""
import hashlib
import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))
import shared_manifest_controller_adapter as adapter

SHA = "a" * 40


class SharedManifestAdapterTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.addCleanup(self.folder.cleanup)
        self.root = Path(self.folder.name)
        (self.root / "releases").mkdir()
        self.files = []
        for name, data, baseline in (
            ("catalogus-public/admin/admin.css", b"a{color:black}", "b" * 64),
            ("private/new_helper.php", b"<?php return true;", "ABSENT"),
        ):
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(data)
            self.files.append({"path": name,
                               "source_sha256": hashlib.sha256(data).hexdigest(),
                               "remote_sha256": baseline})
        self.release = "test-release-354"
        self.manifest = self.root / "releases" / (self.release + ".json")
        self.manifest.write_text(json.dumps({"schema": 1, "files": self.files}))
        self.approval = "\n".join([
            adapter.PREFIX, "release_id=" + self.release, "release_sha=" + SHA,
            *("file=" + f["path"] + "|" + f["source_sha256"] + "|" + f["remote_sha256"]
              for f in self.files),
        ])

    def verify(self, text=None, main=SHA, checkout=SHA):
        return adapter.verify_approval(
            self.approval if text is None else text, main,
            source_root=self.root, checkout_sha=checkout)

    def test_exact_approval_and_two_workflow_inputs(self):
        data = self.verify()
        self.assertEqual(data["files"][1][2], "ABSENT")
        self.assertEqual(adapter.dispatch_command("pecharko/Veiling", data), (
            "gh", "workflow", "run", "shared-ovh-release.yml", "--repo",
            "pecharko/Veiling", "--ref", "main", "-f",
            "release_id=test-release-354", "-f",
            "confirmation=DEPLOY-test-release-354-" + SHA))
        with self.assertRaises(ValueError):
            adapter.dispatch_command("other/Veiling", data)

    def test_reject_wrong_text_hashes_and_files(self):
        variants = [
            self.approval + "\nextra=x",
            self.approval.replace("release_sha=", "sha="),
            self.approval.replace("release_id=", "wrong="),
            self.approval.replace("file=", "path=", 1),
            self.approval.replace("|ABSENT", "|absent"),
            self.approval.replace("|" + "b" * 64, "|" + "c" * 64),
            self.approval.replace(self.files[0]["source_sha256"], "d" * 64),
            self.approval + "\n" + self.approval.splitlines()[3],
            "\n".join(self.approval.splitlines()[:3]),
            "\n".join(self.approval.splitlines()[:-1]),
            "\n".join(self.approval.splitlines()[:3] +
                       self.approval.splitlines()[4:] + self.approval.splitlines()[3:4]),
        ]
        for value in variants:
            with self.subTest(text=value[:80]), self.assertRaises(ValueError):
                self.verify(value)

    def test_reject_stale_main_and_modified_source(self):
        for main, checkout in (("c" * 40, SHA), (SHA, "c" * 40)):
            with self.assertRaises(ValueError):
                self.verify(main=main, checkout=checkout)
        (self.root / self.files[0]["path"]).write_bytes(b"tampered")
        with self.assertRaises(ValueError):
            self.verify()

    def test_reject_protected_files(self):
        for name in ("private/snapshots.php", "private/visitor_stats_daily_cron.php"):
            with self.subTest(name=name):
                p = self.root / name
                p.write_bytes(b"<?php return true;")
                sha = hashlib.sha256(p.read_bytes()).hexdigest()
                self.manifest.write_text(json.dumps({"schema": 1, "files": [{
                    "path": name, "source_sha256": sha, "remote_sha256": "b" * 64
                }]}))
                with self.assertRaises(ValueError):
                    self.verify("\n".join([adapter.PREFIX, "release_id=" + self.release,
                                            "release_sha=" + SHA,
                                            "file=" + name + "|" + sha + "|" + "b" * 64]))

    def test_reordered_manifest_rejected(self):
        self.manifest.write_text(json.dumps({"schema": 1, "files": list(reversed(self.files))}))
        with self.assertRaises(ValueError):
            self.verify()

    def load_controller(self):
        spec = importlib.util.spec_from_file_location(
            "approval_controller_for_test", ROOT / "scripts/verified_deploy_controller.py")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def test_controller_dry_run_then_live_dispatch(self):
        module = self.load_controller()
        issue = {"state": "open", "user": {"login": "pecharko"},
                 "title": "DEPLOY-AUTH: offline test",
                 "labels": [{"name": "approved-deploy-request"}]}
        data = self.verify()
        events = []

        def fake_gh(endpoint, *args):
            if endpoint.endswith("/issues/44") and args:
                self.assertIn("state=closed", args)
                events.append("closed")
                return {}
            if endpoint.endswith("/issues/44"):
                return issue
            if endpoint.endswith("/branches/main"):
                return {"commit": {"sha": SHA}}
            raise AssertionError("Unexpected API: " + endpoint)

        base = {"REPOSITORY": "pecharko/Veiling", "ISSUE_NUMBER": "44",
                "ACTOR": "pecharko", "APPROVAL_TEXT": self.approval}
        with patch.dict(os.environ, {**base, "CONTROLLER_DRY_RUN": "true"}), \
             patch.object(module, "gh", side_effect=fake_gh), \
             patch.object(module, "has_closed_event", return_value=False), \
             patch.object(module.subprocess, "run",
                          return_value=subprocess.CompletedProcess([], 0, SHA + "\n")), \
             patch.object(module, "verify_shared_approval", return_value=data), \
             patch.object(module, "reject_prior_approved_release") as replay, \
             patch.object(module, "dispatch_approved", side_effect=AssertionError("Dispatched in dry run")):
            module.main()
            replay.assert_called_once_with("pecharko/Veiling", "44", adapter.WORKFLOW, SHA,
                                           shared_release_id=self.release)
        self.assertEqual(events, [])
        with patch.dict(os.environ, {**base, "CONTROLLER_DRY_RUN": "false"}), \
             patch.object(module, "gh", side_effect=fake_gh), \
             patch.object(module, "has_closed_event", return_value=False), \
             patch.object(module.subprocess, "run",
                          return_value=subprocess.CompletedProcess([], 0, SHA + "\n")), \
             patch.object(module, "verify_shared_approval", return_value=data), \
             patch.object(module, "reject_prior_approved_release"), \
             patch.object(module, "dispatch_approved", side_effect=lambda cmd: events.append(cmd)):
            module.main()
        self.assertEqual(events, ["closed", adapter.dispatch_command("pecharko/Veiling", data)])

    def test_controller_replay_guard(self):
        module = self.load_controller()
        def fake_gh(endpoint, *args):
            if endpoint.endswith("/issues?state=all&labels=approved-deploy-request&per_page=100"):
                return [{"number": 14, "state": "closed"}]
            if endpoint.endswith("/issues/14/comments?per_page=100"):
                return [{"body": self.approval}]
            raise AssertionError("Unexpected API: " + endpoint)
        with patch.object(module, "gh", side_effect=fake_gh), \
             patch.object(module, "has_closed_event", return_value=True):
            with self.assertRaisesRegex(ValueError, "already authorized"):
                module.reject_prior_approved_release(
                    "pecharko/Veiling", "44", adapter.WORKFLOW, SHA,
                    shared_release_id=self.release)
            module.reject_prior_approved_release(
                "pecharko/Veiling", "44", adapter.WORKFLOW, SHA,
                shared_release_id="other-release")


if __name__ == "__main__":
    unittest.main()
