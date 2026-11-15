"""Offline negative tests for the dedicated two-file v8 protected release.

All SFTP, SQL, GitHub dispatch, production uploads and backup writes are mocked
or absent. Fixtures use temporary files, never live snapshots or credentials.
"""
import json
import os
from pathlib import Path
import sys
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch

from scripts import deploy_snapshot_v8 as adapter

REPO = Path(__file__).resolve().parents[1]


class SnapshotV8ProtectedReleaseTests(unittest.TestCase):
    def fixture(self, root):
        for path in adapter.FILES:
            dest = root / path
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes((REPO / path).read_bytes())
        doc = {"schema": 1, "files": [
            {"path": path, "source_sha256": adapter.engine.digest((root / path).read_bytes()),
             "remote_sha256": ("a" if i == 0 else "b") * 64}
            for i, path in enumerate(adapter.FILES)
        ]}
        file = root / adapter.MANIFEST_NAME
        file.parent.mkdir(parents=True)
        file.write_text(json.dumps(doc), encoding="utf-8")
        return doc, file

    def test_exact_scope_and_shared_private_guard(self):
        self.assertEqual(adapter.FILES, (
            "private/snapshots.php", "catalogus-public/admin/backup.php"))
        self.assertIn("private/snapshots", adapter.engine.PROTECTED)
        self.assertNotEqual(adapter.RELEASE_ID, "snyk-snapshot-delete-20261028")
        self.assertNotEqual(adapter.RELEASE_ID, "artist-publication-snapshot-20261114")
        self.assertEqual(adapter.MANIFEST_NAME,
                         "releases/snapshot-v8-catalog-only-20261115.json")

    def test_valid_two_file_manifest_is_offline_and_pinned(self):
        with TemporaryDirectory() as temp:
            root = Path(temp)
            doc, _ = self.fixture(root)
            with patch.object(adapter.engine, "connect", side_effect=AssertionError("No network")):
                verified, payload, remote = adapter.guarded_load(adapter.RELEASE_ID, source_root=root)
            self.assertEqual(verified, doc)
            self.assertEqual(tuple(payload), adapter.FILES)
            self.assertEqual(tuple(remote), adapter.FILES)
            self.assertEqual(remote[adapter.FILES[0]], "a" * 64)
            self.assertEqual(remote[adapter.FILES[1]], "b" * 64)

    def test_missing_or_wrong_manifest_never_falls_back(self):
        with TemporaryDirectory() as temp:
            root = Path(temp)
            _, manifest = self.fixture(root)
            with self.assertRaises(ValueError):
                adapter.guarded_load("artist-publication-snapshot-20261114", source_root=root)
            manifest.unlink()
            with self.assertRaisesRegex(ValueError, "manifest missing"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)

    def test_manifest_schema_scope_order_and_hash_fail_closed(self):
        with TemporaryDirectory() as temp:
            root = Path(temp)
            original, manifest = self.fixture(root)

            def bad(change):
                doc = json.loads(json.dumps(original))
                change(doc)
                manifest.write_text(json.dumps(doc), encoding="utf-8")
                with self.assertRaises((ValueError, TypeError, KeyError)):
                    adapter.guarded_load(adapter.RELEASE_ID, source_root=root)

            for change in (
                lambda d: d.update(schema=2),
                lambda d: d.update(schema=True),
                lambda d: d.update(override="unsafe"),
                lambda d: d["files"].reverse(),
                lambda d: d["files"].pop(),
                lambda d: d["files"].append(dict(d["files"][0])),
                lambda d: d["files"][0].update(path="private/config.php"),
                lambda d: d["files"][0].update(extra="permit"),
                lambda d: d["files"][0].update(source_sha256="f" * 64),
                lambda d: d["files"][1].update(remote_sha256="ABSENT"),
                lambda d: d["files"][1].update(remote_sha256="not-sha"),
                lambda d: d["files"][0].update(remote_sha256="F" * 64),
            ):
                with self.subTest(change=str(change)):
                    bad(change)
            manifest.write_text('{"schema":1,"schema":1,"files":[]}', encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "Duplicate"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)

    def test_source_drift_v7_format_auth_restore_and_backup_ui_rejected(self):
        with TemporaryDirectory() as temp:
            root = Path(temp)
            doc, manifest = self.fixture(root)
            snapshot = root / adapter.FILES[0]
            backup = root / adapter.FILES[1]
            snapshot.write_bytes(snapshot.read_bytes().replace(
                b"41lokeren-v8-catalog-only", b"41lokeren-v7-lot-audit"))
            doc["files"][0]["source_sha256"] = adapter.engine.digest(snapshot.read_bytes())
            manifest.write_text(json.dumps(doc), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "Unapproved catalog-only"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)
            snapshot.write_bytes((REPO / adapter.FILES[0]).read_bytes())
            snapshot.write_bytes(snapshot.read_bytes() + b"\nDELETE FROM admins;\n")
            doc["files"][0]["source_sha256"] = adapter.engine.digest(snapshot.read_bytes())
            manifest.write_text(json.dumps(doc), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "Unapproved catalog-only"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)
            snapshot.write_bytes((REPO / adapter.FILES[0]).read_bytes())
            doc["files"][0]["source_sha256"] = adapter.engine.digest(snapshot.read_bytes())
            backup.write_bytes(b"<?php echo 'unsafe';")
            doc["files"][1]["source_sha256"] = adapter.engine.digest(backup.read_bytes())
            manifest.write_text(json.dumps(doc), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "Unsafe snapshot-v8 admin"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)

    def test_symlinks_and_disabled_protected_guard_rejected(self):
        with TemporaryDirectory() as temp:
            root = Path(temp)
            _, manifest = self.fixture(root)
            with patch.object(adapter.engine, "PROTECTED", ()):
                with self.assertRaisesRegex(ValueError, "protected"):
                    adapter.guarded_load(adapter.RELEASE_ID, source_root=root)
            original = manifest.read_text()
            manifest.unlink()
            target = root / "other-manifest.json"
            target.write_text(original)
            manifest.symlink_to(target)
            with self.assertRaisesRegex(ValueError, "unsafe"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)
            manifest.unlink()
            manifest.write_text(original)
            backup = root / adapter.FILES[1]
            data = backup.read_bytes()
            backup.unlink()
            other = root / "other.php"
            other.write_bytes(data)
            backup.symlink_to(other)
            with self.assertRaisesRegex(ValueError, "unsafe"):
                adapter.guarded_load(adapter.RELEASE_ID, source_root=root)

    def authorized_env(self):
        sha = "b" * 40
        return {
            "GITHUB_ACTIONS": "true",
            "GITHUB_EVENT_NAME": "workflow_dispatch",
            "GITHUB_REPOSITORY": adapter.REPOSITORY,
            "GITHUB_REF": "refs/heads/main",
            "GITHUB_WORKFLOW_REF": adapter.WORKFLOW_REF,
            "GITHUB_SHA": sha,
            "GITHUB_RUN_ID": "12345",
            "DEPLOY_CONFIRMATION": adapter.PREFIX + adapter.RELEASE_ID + "-" + sha,
            adapter.BACKUP_KEY: adapter.BACKUP_ATTESTATION,
        }

    def test_every_missing_owner_binding_or_backup_denied_before_engine(self):
        good = self.authorized_env()
        for key, value in (
            ("GITHUB_ACTIONS", "false"),
            ("GITHUB_EVENT_NAME", "pull_request"),
            ("GITHUB_REPOSITORY", "attacker/Veiling"),
            ("GITHUB_REF", "refs/heads/preview/unsafe"),
            ("GITHUB_WORKFLOW_REF", "attacker/Veiling/workflow@refs/heads/main"),
            ("GITHUB_SHA", "wrong"),
            ("GITHUB_RUN_ID", "0"),
            ("DEPLOY_CONFIRMATION", "go"),
            (adapter.BACKUP_KEY, "unverified"),
        ):
            with self.subTest(key=key), patch.dict(os.environ, {**good, key: value}, clear=True):
                with patch.object(sys, "argv", ["adapter", "deploy"]), patch.object(
                    adapter.engine, "run", side_effect=AssertionError("Must not run")
                ):
                    with self.assertRaises(ValueError):
                        adapter.main()
        with patch.dict(os.environ, {**good, adapter.BACKUP_KEY: ""}, clear=True):
            with patch.object(sys, "argv", ["adapter", "deploy"]), self.assertRaises(ValueError):
                adapter.main()

    def test_authorized_adapter_only_calls_shared_engine_after_offline_validation(self):
        with TemporaryDirectory() as temp:
            root = Path(temp)
            self.fixture(root)
            env = self.authorized_env()
            observed = []
            original = adapter.engine.load_manifest

            def fake_run():
                observed.append((list(sys.argv), os.environ["DEPLOY_CONFIRMATION"],
                                 adapter.engine.load_manifest is adapter.guarded_load))

            with patch.dict(os.environ, env, clear=True), patch.object(sys, "argv", ["adapter", "deploy"]):
                with patch.object(adapter.engine, "ROOT", root), patch.object(
                    adapter.engine, "connect", side_effect=AssertionError("No real SFTP")
                ), patch.object(adapter.engine, "run", side_effect=fake_run):
                    adapter.main()
                    self.assertEqual(os.environ["DEPLOY_CONFIRMATION"], env["DEPLOY_CONFIRMATION"])
            self.assertIs(adapter.engine.load_manifest, original)
            self.assertEqual(observed, [(
                ["shared_ovh_release.py", "deploy", adapter.RELEASE_ID],
                "DEPLOY-" + adapter.RELEASE_ID + "-" + env["GITHUB_SHA"], True,
            )])

    def test_plan_never_connects_and_requires_authenticated_manifest(self):
        with TemporaryDirectory() as temp:
            root = Path(temp)
            with patch.object(adapter.engine, "ROOT", root), patch.object(
                adapter.engine, "connect", side_effect=AssertionError("No network")
            ), patch.object(adapter.engine, "run", side_effect=AssertionError("No engine")):
                with patch.object(sys, "argv", ["adapter", "plan"]):
                    with self.assertRaisesRegex(ValueError, "manifest missing"):
                        adapter.main()


if __name__ == "__main__":
    unittest.main()
