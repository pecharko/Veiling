"""Offline-only safety tests for the scoped canonical-domain release adapter."""
import json
import os
from pathlib import Path
import sys
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch

from scripts import deploy_canonical_domain as adapter


class CanonicalDomainReleaseTests(unittest.TestCase):
    def test_exact_five_targets(self):
        self.assertEqual(tuple(adapter.TARGETS), (
            "catalogus-public/.htaccess",
            "catalogus-public/index.php",
            "catalogus-public/kavel.php",
            "catalogus-public/pecharko.php",
            "catalogus-public/vorige-editie.php",
        ))
        self.assertNotIn("private/snapshots.php", adapter.ALLOWED)

    def test_no_manifest_fail_closed(self):
        with TemporaryDirectory() as tmp:
            with self.assertRaisesRegex(ValueError, "Missing authenticated"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=tmp)

    def test_exact_manifest_and_source_digest(self):
        with TemporaryDirectory() as tmp:
            root = Path(tmp)
            entries = []
            for name in adapter.TARGETS:
                path = root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(("synthetic " + name).encode())
                entries.append({"path": name, "source_sha256": adapter.engine.digest(path.read_bytes()), "remote_sha256": "a" * 64})
            manifest = root / adapter.MANIFEST_NAME
            manifest.parent.mkdir()
            manifest.write_text(json.dumps({"schema": 1, "files": entries}))
            data, payload, baselines = adapter.guarded_load(adapter.RELEASE_ID, source_root=root)
            self.assertEqual(list(payload), list(adapter.TARGETS))
            self.assertEqual(list(baselines), list(adapter.TARGETS))
            (root / adapter.TARGETS[0]).write_bytes(b"tampered")
            with self.assertRaisesRegex(ValueError, "source drift"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)
            (root / adapter.TARGETS[0]).write_bytes(("synthetic " + adapter.TARGETS[0]).encode())
            data["files"][0]["path"] = "private/snapshots.php"
            manifest.write_text(json.dumps(data))
            with self.assertRaisesRegex(ValueError, "order or scope"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)

    def test_invalid_authorization_never_reaches_engine(self):
        for confirmation in ("go", "", "DEPLOY-" + adapter.RELEASE_ID + "-" + "a" * 40):
            with self.subTest(confirmation=confirmation), patch.dict(os.environ, {
                "GITHUB_SHA": "a" * 40, "DEPLOY_CONFIRMATION": confirmation
            }), patch.object(adapter.engine, "run", side_effect=AssertionError("Must not run")):
                with patch.object(sys, "argv", ["adapter", "deploy"]), self.assertRaises(ValueError):
                    adapter.main()

    def test_offline_plan_preserves_standard_loader(self):
        orig_loader, orig_argv = adapter.engine.load_manifest, sys.argv
        with patch.object(sys, "argv", ["adapter", "plan"]), patch.object(
            adapter.engine, "run", side_effect=ValueError("missing manifest")
        ):
            with self.assertRaisesRegex(ValueError, "missing manifest"):
                adapter.main()
        self.assertIs(adapter.engine.load_manifest, orig_loader)
        self.assertIs(sys.argv, orig_argv)


if __name__ == "__main__":
    unittest.main()
