"""Offline security regression tests for the independent artist slogan snapshot adapter."""
import json
import os
from pathlib import Path
import sys
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch

from scripts import deploy_artist_slogan_snapshot as adapter


class ArtistSloganSnapshotReleaseTests(unittest.TestCase):
    def fixture(self, root):
        path = root / adapter.TARGET
        path.parent.mkdir(parents=True)
        path.write_bytes(b"<?php // 41lokeren-v7-lot-audit artist_slogan isolated fixture")
        manifest = {"schema": 1, "files": [{
            "path": adapter.TARGET,
            "source_sha256": adapter.engine.digest(path.read_bytes()),
            "remote_sha256": "a" * 64,
        }]}
        manifest_path = root / adapter.MANIFEST_NAME
        manifest_path.parent.mkdir(parents=True)
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
        return path, manifest_path

    def test_isolated_protected_scope_and_old_bank_release_unchanged(self):
        self.assertEqual(adapter.TARGET, "private/snapshots.php")
        self.assertNotEqual(adapter.RELEASE_ID, "lot-audit-snapshot-v7-20261111")
        self.assertNotEqual(adapter.MANIFEST_NAME, "releases/lot-audit-snapshot-v7-20261111.json")
        self.assertIn("private/snapshots", adapter.engine.PROTECTED)

    def test_manifest_and_exact_v7_source(self):
        with TemporaryDirectory() as temp:
            root = Path(temp)
            path, manifest_path = self.fixture(root)
            manifest, payload, expected = adapter.guarded_load(adapter.RELEASE_ID, source_root=root)
            self.assertEqual(set(payload), {adapter.TARGET})
            self.assertEqual(expected[adapter.TARGET], "a" * 64)
            self.assertEqual(manifest["files"][0]["source_sha256"], adapter.engine.digest(path.read_bytes()))
            path.write_bytes(b"<?php // other version")
            with self.assertRaisesRegex(ValueError, "source drift"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)
            manifest_path.unlink()
            with self.assertRaisesRegex(ValueError, "manifest missing"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)

    def test_reject_remote_absent_wrong_target_and_wrong_version(self):
        with TemporaryDirectory() as temp:
            root = Path(temp)
            path, manifest_path = self.fixture(root)
            doc = json.loads(manifest_path.read_text())
            doc["files"][0]["remote_sha256"] = "ABSENT"
            manifest_path.write_text(json.dumps(doc))
            with self.assertRaisesRegex(ValueError, "remote SHA"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)
            doc["files"][0]["remote_sha256"] = "a" * 64
            doc["files"][0]["path"] = "private/config.php"
            manifest_path.write_text(json.dumps(doc))
            with self.assertRaisesRegex(ValueError, "Wrong protected"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)
            doc["files"][0]["path"] = adapter.TARGET
            path.write_bytes(b"<?php // old snapshot")
            doc["files"][0]["source_sha256"] = adapter.engine.digest(path.read_bytes())
            manifest_path.write_text(json.dumps(doc))
            with self.assertRaisesRegex(ValueError, "lacks v7 artist slogan"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)

    def test_no_deploy_without_exact_owner_and_schema_confirmation(self):
        sha = "b" * 40
        confirmation = adapter.PREFIX + adapter.RELEASE_ID + "-" + sha
        for owner, schema in [
            ("go", adapter.SCHEMA_ATTESTATION),
            (confirmation, ""),
            (confirmation, "wrong-schema"),
        ]:
            with patch.dict(os.environ, {
                "GITHUB_SHA": sha,
                "DEPLOY_CONFIRMATION": owner,
                "ARTIST_SLOGAN_SCHEMA_READY": schema,
            }), patch.object(sys, "argv", ["adapter", "deploy"]), patch.object(
                adapter.engine, "run", side_effect=AssertionError("engine must not run")
            ):
                with self.assertRaises(ValueError):
                    adapter.main()

    def test_no_network_in_plan_without_manifest(self):
        with patch.object(sys, "argv", ["adapter", "plan"]), patch.object(
            adapter.engine, "connect", side_effect=AssertionError("no network")
        ), patch.object(adapter.engine, "run", side_effect=ValueError("manifest absent")):
            with self.assertRaisesRegex(ValueError, "manifest absent"):
                adapter.main()


if __name__ == "__main__":
    unittest.main()
