"""Offline tests for the protected homepage snapshot code release."""
import json
import os
from pathlib import Path
import shutil
import sys
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch

from scripts import deploy_homepage_visibility_snapshot as release


class HomepageVisibilitySnapshotReleaseTests(unittest.TestCase):
    def fixture(self, root):
        manifest = root / release.MANIFEST
        source = root / release.TARGET
        for path in (manifest, source):
            path.parent.mkdir()
            shutil.copyfile(release.engine.ROOT / path.relative_to(root), path)
        return manifest, source

    def test_exact_release_can_be_planned_offline(self):
        manifest, payload, baselines = release.load_manifest(release.RELEASE_ID)
        self.assertEqual(set(payload), {release.TARGET})
        self.assertEqual(baselines, {release.TARGET: release.BASELINE})
        self.assertEqual(manifest["files"][0]["source_sha256"], release.engine.digest(payload[release.TARGET]))
        with patch.object(sys, "argv", ["adapter", "plan"]), patch.object(
            release.engine, "connect", side_effect=AssertionError("Plan must not connect to OVH")
        ):
            release.main()

    def test_shared_loader_still_refuses_the_snapshot_manifest(self):
        with self.assertRaisesRegex(ValueError, "protected path"):
            release.engine.load_manifest(release.RELEASE_ID)

    def test_rejects_other_paths_changed_hashes_and_ambiguous_manifest(self):
        with TemporaryDirectory() as tmp:
            root = Path(tmp)
            manifest, source = self.fixture(root)
            reviewed = manifest.read_text(encoding="utf-8")
            variations = [
                reviewed.replace(release.TARGET, "catalogus-public/index.php"),
                reviewed.replace(release.TARGET, "private/config.php"),
                reviewed.replace(release.BASELINE, "ABSENT"),
                reviewed.replace(release.BASELINE, "a" * 64),
                reviewed.replace(release.SOURCE, "b" * 64),
                reviewed.replace('"schema": 1', '"schema": 1, "schema": 1'),
                json.dumps({"schema": 1, "files": json.loads(reviewed)["files"] * 2}),
            ]
            for variation in variations:
                with self.subTest(variation=variation):
                    manifest.write_text(variation, encoding="utf-8")
                    with self.assertRaises(ValueError):
                        release.load_manifest(release.RELEASE_ID, source_root=root)
            manifest.write_text(reviewed, encoding="utf-8")
            with self.assertRaises(ValueError):
                release.load_manifest("some-other-release", source_root=root)
            source.write_bytes(source.read_bytes() + b"\n")
            with self.assertRaisesRegex(ValueError, "source drift"):
                release.load_manifest(release.RELEASE_ID, source_root=root)
            source.unlink()
            source.symlink_to(release.engine.ROOT / release.TARGET)
            with self.assertRaisesRegex(ValueError, "unsafe release file"):
                release.load_manifest(release.RELEASE_ID, source_root=root)

    def dispatch_environment(self):
        sha = "c" * 40
        return {
            "GITHUB_ACTIONS": "true",
            "GITHUB_ACTOR": "pecharko",
            "GITHUB_TRIGGERING_ACTOR": "pecharko",
            "GITHUB_REPOSITORY": "pecharko/Veiling",
            "GITHUB_EVENT_NAME": "workflow_dispatch",
            "GITHUB_WORKFLOW_REF": release.WORKFLOW,
            "GITHUB_REF": "refs/heads/main",
            "GITHUB_SHA": sha,
            "GITHUB_RUN_ID": "123",
            "GITHUB_RUN_ATTEMPT": "1",
            "DEPLOY_CONFIRMATION": f"HOMEPAGE-SNAPSHOT-DEPLOY-{release.RELEASE_ID}-{sha}",
            "HOMEPAGE_SNAPSHOT_BACKUP_READY": release.BACKUP_READY,
        }

    def test_invalid_dispatch_aborts_before_entering_the_writer(self):
        env = self.dispatch_environment()
        cases = {
            "GITHUB_ACTIONS": "false",
            "GITHUB_ACTOR": "other-actor",
            "GITHUB_TRIGGERING_ACTOR": "other-actor",
            "GITHUB_REPOSITORY": "other/Veiling",
            "GITHUB_EVENT_NAME": "pull_request",
            "GITHUB_WORKFLOW_REF": "another-workflow",
            "GITHUB_REF": "refs/heads/other",
            "GITHUB_SHA": "d" * 40,
            "GITHUB_RUN_ID": "0",
            "GITHUB_RUN_ATTEMPT": "2",
            "DEPLOY_CONFIRMATION": "go",
            "HOMEPAGE_SNAPSHOT_BACKUP_READY": "",
        }
        for key, invalid in cases.items():
            with self.subTest(key=key), patch.dict(os.environ, {**env, key: invalid}, clear=True), patch.object(
                sys, "argv", ["adapter", "deploy"]
            ), patch.object(release.engine, "run") as writer:
                with self.assertRaisesRegex(ValueError, "Exact owner dispatch"):
                    release.main()
                writer.assert_not_called()

    def test_dispatch_passes_only_the_pinned_release_to_the_existing_writer(self):
        env = self.dispatch_environment()
        original = release.engine.load_manifest

        def check_writer_inputs():
            self.assertEqual(sys.argv, ["shared_ovh_release.py", "deploy", release.RELEASE_ID])
            self.assertEqual(os.environ["DEPLOY_CONFIRMATION"], f'DEPLOY-{release.RELEASE_ID}-{env["GITHUB_SHA"]}')
            self.assertIs(release.engine.load_manifest, release.load_manifest)
            _, payload, baselines = release.engine.load_manifest(release.RELEASE_ID)
            self.assertEqual(set(payload), {release.TARGET})
            self.assertEqual(baselines, {release.TARGET: release.BASELINE})
            raise RuntimeError("Simulated writer failure")

        with patch.dict(os.environ, env, clear=True), patch.object(sys, "argv", ["adapter", "deploy"]), patch.object(
            release.engine, "run", side_effect=check_writer_inputs
        ) as writer:
            with self.assertRaisesRegex(RuntimeError, "Simulated writer failure"):
                release.main()
            writer.assert_called_once()
            self.assertEqual(os.environ["DEPLOY_CONFIRMATION"], env["DEPLOY_CONFIRMATION"])
            self.assertEqual(sys.argv, ["adapter", "deploy"])
        self.assertIs(release.engine.load_manifest, original)
