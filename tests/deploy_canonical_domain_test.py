"""Offline-only safety tests for the scoped canonical-domain release adapter."""
import io
import json
import os
import stat
from types import SimpleNamespace
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


    def test_preserves_exact_existing_production_htaccess_and_checks_baseline(self):
        original = b"# existing security rules\\nDeny from all\\n"
        baseline = adapter.engine.digest(original)
        class Sftp:
            def __enter__(self):
                return self
            def __exit__(self, *_):
                return False
            def lstat(self, path):
                data = b"dashboard-table" if path.endswith("/admin/index.php") else original
                return SimpleNamespace(st_mode=stat.S_IFREG | 0o600, st_size=len(data))
            def open(self, path, mode):
                self_ = b"dashboard-table" if path.endswith("/admin/index.php") else original
                if mode != "rb":
                    raise AssertionError("Write forbidden")
                return io.BytesIO(self_)
        client = SimpleNamespace(open_sftp=lambda: Sftp(), close=lambda: None)
        self.assertEqual(adapter.read_pinned_htaccess(lambda: (client, "/fixture"), baseline), original)
        with self.assertRaisesRegex(ValueError, "drift"):
            adapter.read_pinned_htaccess(lambda: (client, "/fixture"), "a" * 64)
        with self.assertRaisesRegex(ValueError, "absent"):
            adapter.read_pinned_htaccess(lambda: (client, "/fixture"), "ABSENT")

    def test_combined_template_retains_original_bytes(self):
        original = b"Options -Indexes\r\nRewriteRule ^old$ /new [L]\r\n"
        with TemporaryDirectory() as tmp:
            root = Path(tmp)
            entries = []
            for name in adapter.TARGETS:
                path = root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(b"# redirect template\n" if name == adapter.HTACCESS else b"<?php echo 1;")
                entries.append({"path": name, "source_sha256": adapter.engine.digest(path.read_bytes()),
                                "remote_sha256": adapter.engine.digest(original) if name == adapter.HTACCESS else "a" * 64})
            manifest = root / adapter.MANIFEST_NAME
            manifest.parent.mkdir()
            manifest.write_text(json.dumps({"schema": 1, "files": entries}))
            with patch.object(adapter, "read_pinned_htaccess", return_value=original) as read:
                _, payload, expected = adapter.guarded_load(
                    adapter.RELEASE_ID, source_root=root, preserve_remote_htaccess=True)
            read.assert_called_once()
            self.assertEqual(payload[adapter.HTACCESS], b"# redirect template\n\n" + original)
            self.assertEqual(expected[adapter.HTACCESS], adapter.engine.digest(original))

    def test_evidence_exception_is_only_one_exact_path(self):
        from scripts import ovh_deploy_evidence_contract
        rows = [{"path": name, "source_sha256": "a"*64,
                 "baseline_sha256": "b"*64, "readback_sha256": "a"*64}
                for name in adapter.TARGETS]
        event = {"schema": 1, "release_id": adapter.RELEASE_ID,
                 "release_sha": "a"*40, "run_id": 1234, "status": "VERIFIED",
                 "files": rows, "rollback_artifact": None, "browser_acceptance": "PENDING"}
        self.assertIs(adapter.canonical_evidence_validate(event, ovh_deploy_evidence_contract.validate), event)
        altered = json.loads(json.dumps(event))
        altered["files"][0]["path"] = "private/.env"
        with self.assertRaisesRegex(ValueError, "exact five-file"):
            adapter.canonical_evidence_validate(altered, ovh_deploy_evidence_contract.validate)
        invalid = json.loads(json.dumps(event))
        invalid["files"][0]["readback_sha256"] = "c"*64
        with self.assertRaisesRegex(ValueError, "without matching readback"):
            adapter.canonical_evidence_validate(invalid, ovh_deploy_evidence_contract.validate)


if __name__ == "__main__":
    unittest.main()
