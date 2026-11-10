"""Offline safety checks for bank snapshot protected one-file release."""
import json
from pathlib import Path
from tempfile import TemporaryDirectory
import os
import sys
import unittest
from unittest.mock import patch

from scripts import deploy_bank_snapshot as adapter


class BankSnapshotReleaseTests(unittest.TestCase):
    def fixture(self, root):
        path = root / adapter.TARGET
        path.parent.mkdir(parents=True)
        path.write_bytes(b"<?php // isolated snapshot test")
        manifest = {
            "schema": 1, "files": [{
                "path": adapter.TARGET,
                "source_sha256": adapter.engine.digest(path.read_bytes()),
                "remote_sha256": "a" * 64,
            }]
        }
        mpath = root / adapter.MANIFEST_NAME
        mpath.parent.mkdir(parents=True)
        mpath.write_text(json.dumps(manifest))
        return manifest, path, mpath

    def test_exact_scope_and_no_historic_gate_mutation(self):
        self.assertEqual(adapter.TARGET, "private/snapshots.php")
        self.assertNotEqual(adapter.RELEASE_ID, "protected-catalog-snapshot-20261106")
        self.assertIn("private/snapshots", adapter.engine.PROTECTED)
        with TemporaryDirectory() as tmp:
            root = Path(tmp)
            manifest, path, _ = self.fixture(root)
            found, payload, expected = adapter.guarded_load(adapter.RELEASE_ID, source_root=root)
            self.assertEqual(found, manifest)
            self.assertEqual(list(payload), [adapter.TARGET])
            self.assertEqual(list(expected), [adapter.TARGET])

    def test_no_manifest_or_unverified_baseline_rejected(self):
        with TemporaryDirectory() as tmp:
            root = Path(tmp)
            with self.assertRaisesRegex(ValueError, "manifest missing"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)
            _, _, m = self.fixture(root)
            doc = json.loads(m.read_text())
            doc["files"][0]["remote_sha256"] = "ABSENT"
            m.write_text(json.dumps(doc))
            with self.assertRaisesRegex(ValueError, "authenticated remote"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)

    def test_wrong_path_and_drift_fails_closed(self):
        with TemporaryDirectory() as tmp:
            root = Path(tmp)
            _, path, m = self.fixture(root)
            path.write_bytes(b"corrupt")
            with self.assertRaisesRegex(ValueError, "source drift"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)
            doc = json.loads(m.read_text())
            doc["files"][0]["path"] = "private/config.php"
            m.write_text(json.dumps(doc))
            with self.assertRaisesRegex(ValueError, "Wrong protected snapshot path"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)

    def test_unauthorized_or_migration_missing_never_reaches_engine(self):
        sha = "a" * 40
        confirmation = adapter.PREFIX + adapter.RELEASE_ID + "-" + sha
        original = adapter.engine.load_manifest
        for confirm, schema in [("", ""), (confirmation, ""), ("go", "BANK-SCHEMA-VERIFIED-20261110")]:
            with patch.dict(os.environ, {"GITHUB_SHA": sha,
                                        "DEPLOY_CONFIRMATION": confirm,
                                        "BANK_SCHEMA_READY": schema}), \
                 patch.object(sys, "argv", ["adapter", "deploy"]), \
                 patch.object(adapter.engine, "run", side_effect=AssertionError("No engine")):
                with self.assertRaises(ValueError):
                    adapter.main()
        self.assertIs(adapter.engine.load_manifest, original)

    def test_plan_never_connects_when_manifest_missing(self):
        with patch.object(sys, "argv", ["adapter", "plan"]), \
             patch.object(adapter.engine, "connect", side_effect=AssertionError("No network")), \
             patch.object(adapter.engine, "run", side_effect=ValueError("manifest absent")):
            with self.assertRaisesRegex(ValueError, "manifest absent"):
                adapter.main()


if __name__ == "__main__":
    unittest.main()
