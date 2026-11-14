"""Offline fail-closed regression for artist publication snapshot controller.

No calls to OVH, SFTP, GitHub workflow dispatch, DB, or snapshot operations.
"""
import json
import os
from pathlib import Path
import sys
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch

from scripts import deploy_artist_publication_snapshot as adapter


class ArtistPublicationSnapshotReleaseTests(unittest.TestCase):
    def fixture(self, root):
        source = root / adapter.TARGET
        source.parent.mkdir(parents=True)
        source.write_bytes(
            b"<?php // 41lokeren-v7-lot-audit\n"
            b"const SNAP_INSERT = ['lot_sellers'=>['artist_slogan','artist_published']];\n"
        )
        manifest_path = root / adapter.MANIFEST_NAME
        manifest_path.parent.mkdir(parents=True)
        manifest_path.write_text(json.dumps({"schema": 1, "files": [{
            "path": adapter.TARGET,
            "source_sha256": adapter.engine.digest(source.read_bytes()),
            "remote_sha256": "a" * 64
        }]}), encoding="utf-8")
        return source, manifest_path

    def test_protected_scope_without_historical_release_changes(self):
        self.assertEqual(adapter.TARGET, "private/snapshots.php")
        self.assertIn("private/snapshots", adapter.engine.PROTECTED)
        self.assertNotEqual(adapter.RELEASE_ID, "artist-slogan-snapshot-20261112")
        self.assertNotEqual(adapter.RELEASE_ID, "lot-audit-snapshot-v7-20261111")
        self.assertEqual(adapter.MANIFEST_NAME, "releases/artist-publication-snapshot-20261114.json")

    def test_accept_one_signed_source_and_authenticated_remote_baseline(self):
        with TemporaryDirectory() as temp:
            root = Path(temp)
            source, _ = self.fixture(root)
            manifest, payload, baseline = adapter.guarded_load(adapter.RELEASE_ID, source_root=root)
            self.assertEqual(set(payload), {adapter.TARGET})
            self.assertEqual(baseline[adapter.TARGET], "a" * 64)
            self.assertEqual(manifest["files"][0]["source_sha256"],
                             adapter.engine.digest(source.read_bytes()))

    def test_reject_any_unverified_manifest_or_source(self):
        with TemporaryDirectory() as temp:
            root = Path(temp)
            source, manifest_file = self.fixture(root)
            original = json.loads(manifest_file.read_text())

            def bad(**changes):
                document = json.loads(json.dumps(original))
                document["files"][0].update(changes)
                manifest_file.write_text(json.dumps(document))
                with self.assertRaises(ValueError):
                    adapter.guarded_load(adapter.RELEASE_ID, source_root=root)

            bad(path="private/config.php")
            bad(remote_sha256="ABSENT")
            bad(remote_sha256="not-a-hash")
            bad(source_sha256="f" * 64)
            manifest_file.write_text(json.dumps(original))
            source.write_bytes(b"<?php // old snapshot without publication")
            with self.assertRaisesRegex(ValueError, "lacks approved"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)
            source.write_bytes(
                b"<?php // 41lokeren-v7-lot-audit SNAP_INSERT "
                b"['artist_slogan','artist_published']"
            )
            manifest_file.write_text(json.dumps(original))
            source_sha = adapter.engine.digest(source.read_bytes())
            original["files"][0]["source_sha256"] = source_sha
            manifest_file.write_text(json.dumps(original))
            self.assertEqual(adapter.guarded_load(adapter.RELEASE_ID, source_root=root)[1][adapter.TARGET],
                             source.read_bytes())
            manifest_file.unlink()
            with self.assertRaisesRegex(ValueError, "manifest missing"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)
            with self.assertRaises(ValueError):
                adapter.guarded_load("artist-slogan-snapshot-20261112", source_root=root)

    def test_reject_extra_manifest_fields_and_multiple_paths(self):
        with TemporaryDirectory() as temp:
            root = Path(temp)
            _, manifest_path = self.fixture(root)
            document = json.loads(manifest_path.read_text())
            document["files"].append(dict(document["files"][0]))
            manifest_path.write_text(json.dumps(document))
            with self.assertRaisesRegex(ValueError, "Exactly one"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)
            document["files"] = document["files"][:1]
            document["files"][0]["unsafe_override"] = "yes"
            manifest_path.write_text(json.dumps(document))
            with self.assertRaisesRegex(ValueError, "Wrong protected"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)

    def test_fail_closed_without_owner_and_schema_attestation(self):
        sha = "b" * 40
        approval = adapter.PREFIX + adapter.RELEASE_ID + "-" + sha
        safe = {
            "GITHUB_ACTIONS": "true",
            "GITHUB_EVENT_NAME": "workflow_dispatch",
            "GITHUB_REF": "refs/heads/main",
            "GITHUB_SHA": sha,
            "DEPLOY_CONFIRMATION": approval,
            adapter.SCHEMA_KEY: adapter.SCHEMA_ATTESTATION,
        }
        overrides = [
            {"DEPLOY_CONFIRMATION": "go"},
            {"GITHUB_SHA": "x" * 40},
            {"GITHUB_REF": "refs/heads/preview/ui"},
            {"GITHUB_EVENT_NAME": "pull_request"},
            {"GITHUB_ACTIONS": "false"},
            {adapter.SCHEMA_KEY: ""},
            {adapter.SCHEMA_KEY: "unverified"},
        ]
        for over in overrides:
            with self.subTest(over=over), patch.dict(os.environ, {**safe, **over}), patch.object(
                sys, "argv", ["controller", "deploy"]
            ), patch.object(adapter.engine, "run", side_effect=AssertionError("must not run")):
                with self.assertRaises(ValueError):
                    adapter.main()

    def test_authorized_path_uses_shared_engine_only_when_mocked(self):
        sha = "b" * 40
        approval = adapter.PREFIX + adapter.RELEASE_ID + "-" + sha
        env = {
            "GITHUB_ACTIONS": "true", "GITHUB_EVENT_NAME": "workflow_dispatch",
            "GITHUB_REF": "refs/heads/main", "GITHUB_SHA": sha,
            "DEPLOY_CONFIRMATION": approval,
            adapter.SCHEMA_KEY: adapter.SCHEMA_ATTESTATION,
        }
        seen = []
        def fake_run():
            seen.append((list(sys.argv), os.environ["DEPLOY_CONFIRMATION"],
                         adapter.engine.load_manifest is adapter.guarded_load))
        original = adapter.engine.load_manifest
        with patch.dict(os.environ, env), patch.object(sys, "argv", ["controller", "deploy"]), patch.object(
            adapter.engine, "run", side_effect=fake_run
        ):
            adapter.main()
            self.assertEqual(os.environ["DEPLOY_CONFIRMATION"], approval)
        self.assertIs(adapter.engine.load_manifest, original)
        self.assertEqual(seen, [(
            ["shared_ovh_release.py", "deploy", adapter.RELEASE_ID],
            "DEPLOY-" + adapter.RELEASE_ID + "-" + sha, True
        )])

    def test_no_remote_access_in_plan_or_missing_manifest(self):
        with patch.object(sys, "argv", ["controller", "plan"]), patch.object(
            adapter.engine, "connect", side_effect=AssertionError("network prohibited")
        ), patch.object(adapter.engine, "run", side_effect=ValueError("manifest missing")):
            with self.assertRaisesRegex(ValueError, "manifest missing"):
                adapter.main()


if __name__ == "__main__":
    unittest.main()
