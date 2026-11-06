"""Offline only: synthetic remote bytes, temporary backups, no SFTP or DB."""
from contextlib import ExitStack, redirect_stdout, redirect_stderr
import io
import json
import os
from pathlib import Path
import stat
import sys
from tempfile import TemporaryDirectory
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

from scripts import deploy_protected_catalog_snapshot as p
from scripts import ovh_deploy_evidence_contract as evidence_contract

ROOT = p.engine.ROOT
COMMIT = "a" * 40
OLD = b"synthetic previous snapshot PHP code"


class FakeSFTP:
    """Just enough SFTP to exercise the unchanged engine, including rollback."""
    def __init__(self, backup, baseline=OLD, corrupt=False, fail_rollback=False):
        self.backup = backup
        self.target = "/fixture/" + p.TARGET
        self.files = {self.target: baseline,
                      "/fixture/catalogus-public/admin/index.php": b"dashboard-table"}
        self.writes = []
        self.corrupt = corrupt
        self.fail_rollback = fail_rollback

    def __enter__(self):
        return self

    def __exit__(self, *args):
        pass

    def lstat(self, path):
        if path not in self.files:
            raise FileNotFoundError(2, "missing fixture")
        return SimpleNamespace(st_mode=stat.S_IFREG | 0o600, st_size=len(self.files[path]))

    def open(self, path, mode):
        if mode == "rb":
            return io.BytesIO(self.files[path])
        if path != self.target or mode != "wb":
            raise AssertionError("Unexpected write scope")
        # Backup and its integrity metadata must exist before *every* write.
        assert (self.backup / p.TARGET).read_bytes() == OLD
        metadata = json.loads((self.backup / "manifest.json").read_text())
        assert metadata["previous"] == {p.TARGET: p.engine.digest(OLD)}
        assert metadata["release_id"] == p.RELEASE_ID
        self.writes.append(path)
        if self.fail_rollback and len(self.writes) > 1:
            raise OSError("synthetic rollback failure")
        remote = self

        class Writer(io.BytesIO):
            def close(self):
                if not self.closed:
                    raw = self.getvalue()
                    remote.files[path] = b"synthetic corrupt upload" if remote.corrupt and len(remote.writes) == 1 else raw
                super().close()

        return Writer()


class ProtectedCatalogSnapshotTests(unittest.TestCase):
    def invoke(self, mode):
        with patch.object(sys, "argv", ["adapter", mode]), redirect_stdout(io.StringIO()):
            p.main()

    def test_exact_pinned_scope_and_real_source(self):
        self.assertEqual(p.RELEASE_ID, "protected-catalog-snapshot-20261106")
        self.assertEqual(p.TARGET, "private/snapshots.php")
        self.assertEqual(p.SOURCE_SHA, "f8909ee8c06d7fcca81d9a5ce2784d0e87bf60932ea5e0c25f08009e7eb73ebc")
        self.assertEqual(p.REMOTE_SHA, "1c974785e2cbb7a278320c7cc67d6bcf4c8f1eab42d02b707f18abc08fa0edd2")
        with patch.dict(os.environ, {"EXPECTED_SNAPSHOT_SHA": "b" * 64}, clear=True):
            manifest, payload, expected = p.guarded_load(p.RELEASE_ID)
        self.assertEqual(manifest, {"schema": 1, "files": [{"path": p.TARGET,
                         "source_sha256": p.SOURCE_SHA, "remote_sha256": p.REMOTE_SHA}]})
        self.assertEqual(payload, {p.TARGET: (ROOT / p.TARGET).read_bytes()})
        self.assertEqual(expected, {p.TARGET: p.REMOTE_SHA})

    def test_wrong_release_and_unsafe_sources_rejected(self):
        for release in ("other", p.RELEASE_ID + "/../other", "", None):
            with self.subTest(release=release), self.assertRaises(ValueError):
                p.guarded_load(release)
        for kind in ("missing", "empty", "modified", "oversize", "symlink", "parent_symlink"):
            with self.subTest(kind=kind), TemporaryDirectory() as tmp:
                root = Path(tmp)
                private = root / "private"
                private.mkdir()
                target = root / p.TARGET
                if kind == "empty":
                    target.touch()
                elif kind == "modified":
                    target.write_bytes(b"modified")
                elif kind == "oversize":
                    with target.open("wb") as f:
                        f.truncate(p.engine.MAX_FILE + 1)
                elif kind == "symlink":
                    target.symlink_to(ROOT / p.TARGET)
                elif kind == "parent_symlink":
                    private.rmdir()
                    private.symlink_to(ROOT / "private", target_is_directory=True)
                with self.assertRaises(ValueError):
                    p.guarded_load(p.RELEASE_ID, source_root=root)

    def test_real_plan_is_offline_and_restores_generic_loader(self):
        loader, argv, paths = p.engine.load_manifest, sys.argv, list(sys.path)
        with patch.object(p.engine, "connect", side_effect=AssertionError("network forbidden")):
            self.invoke("plan")
        self.assertIs(p.engine.load_manifest, loader)
        self.assertIs(sys.argv, argv)
        self.assertEqual(sys.path, paths)

    def test_generic_denylist_still_rejects_snapshot_after_plan(self):
        self.invoke("plan")
        with TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "releases").mkdir()
            manifest, _, _ = p.guarded_load(p.RELEASE_ID)
            (root / "releases" / (p.RELEASE_ID + ".json")).write_text(json.dumps(manifest))
            with self.assertRaisesRegex(ValueError, "protected path"):
                p.engine.load_manifest(p.RELEASE_ID, source_root=root)

    def test_invalid_modes_and_authorizations_never_connect(self):
        with patch.object(p.engine, "connect", side_effect=AssertionError("network forbidden")):
            for argv in (["adapter"], ["adapter", "preflight"], ["adapter", "deploy", "extra"]):
                with patch.object(sys, "argv", argv), self.assertRaises(ValueError):
                    p.main()
            for sha, confirmation in (("", ""), (COMMIT, "go"), (COMMIT, "DEPLOY-" + p.RELEASE_ID + "-" + COMMIT),
                                      (COMMIT, "PROTECTED-SNAPSHOT-DEPLOY-other-" + COMMIT),
                                      (COMMIT, "PROTECTED-SNAPSHOT-DEPLOY-" + p.RELEASE_ID + "-" + "b" * 40),
                                      ("z" * 40, "PROTECTED-SNAPSHOT-DEPLOY-" + p.RELEASE_ID + "-" + "z" * 40)):
                with self.subTest(sha=sha, confirmation=confirmation), patch.dict(os.environ, {
                        "GITHUB_SHA": sha, "DEPLOY_CONFIRMATION": confirmation}, clear=True):
                    with self.assertRaises(ValueError):
                        self.invoke("deploy")

    def test_authorized_but_changed_source_stops_before_network_and_restores_loader(self):
        loader = p.engine.load_manifest
        confirmation = "PROTECTED-SNAPSHOT-DEPLOY-" + p.RELEASE_ID + "-" + COMMIT
        with TemporaryDirectory() as tmp, patch.object(p.engine, "ROOT", Path(tmp)), patch.dict(os.environ, {
                "GITHUB_SHA": COMMIT, "DEPLOY_CONFIRMATION": confirmation}, clear=True):
            with patch.object(p.engine, "connect", side_effect=AssertionError("network forbidden")):
                with self.assertRaisesRegex(ValueError, "unsafe protected source"):
                    self.invoke("deploy")
            self.assertIs(p.engine.load_manifest, loader)
            self.assertEqual(os.environ["DEPLOY_CONFIRMATION"], confirmation)

    def exercise_engine(self, *, baseline=OLD, corrupt=False, fail_rollback=False, preexisting_backup=False):
        with TemporaryDirectory() as tmp, ExitStack() as stack:
            root = Path(tmp)
            target = root / p.TARGET
            target.parent.mkdir()
            target.write_bytes((ROOT / p.TARGET).read_bytes())
            backup = root / "deployment-backups"
            if preexisting_backup:
                backup.mkdir()
            remote = FakeSFTP(backup, baseline, corrupt, fail_rollback)
            client = Mock()
            client.open_sftp.return_value = remote
            original_loader = p.engine.load_manifest
            original_confirmation = "PROTECTED-SNAPSHOT-DEPLOY-" + p.RELEASE_ID + "-" + COMMIT
            stack.enter_context(patch.dict(os.environ, {"GITHUB_SHA": COMMIT, "GITHUB_RUN_ID": "123",
                                "DEPLOY_CONFIRMATION": original_confirmation}, clear=True))
            stack.enter_context(patch.dict(sys.modules, {"ovh_deploy_evidence_contract": evidence_contract}))
            stack.enter_context(patch.object(p.engine, "ROOT", root))
            stack.enter_context(patch.object(p.engine, "BACKUP", backup))
            # Synthetic baseline only; keep real SHA-256 and real candidate bytes.
            stack.enter_context(patch.object(p, "REMOTE_SHA", p.engine.digest(OLD)))
            stack.enter_context(patch.object(p.engine, "connect", return_value=(client, "/fixture")))
            error = None
            with redirect_stderr(io.StringIO()):
                try:
                    self.invoke("deploy")
                except RuntimeError as exc:
                    error = exc
            evidence = evidence_contract.load(root / "deployment-evidence.json")
            self.assertIs(p.engine.load_manifest, original_loader)
            self.assertEqual(os.environ["DEPLOY_CONFIRMATION"], original_confirmation)
            client.close.assert_called_once()
            self.assertEqual([row["path"] for row in evidence["files"]], [p.TARGET])
            if baseline != OLD:
                self.assertFalse(backup.exists())
            return remote, evidence, error

    def test_backup_before_only_target_write_and_matching_readback_evidence(self):
        remote, evidence, error = self.exercise_engine()
        self.assertIsNone(error)
        self.assertEqual(remote.writes, [remote.target])
        self.assertEqual(remote.files[remote.target], (ROOT / p.TARGET).read_bytes())
        self.assertEqual(evidence["status"], "VERIFIED")
        self.assertEqual(evidence["files"][0]["readback_sha256"], p.SOURCE_SHA)
        self.assertEqual(evidence["browser_acceptance"], "PENDING")
        self.assertIsNone(evidence["rollback_artifact"])

    def test_drift_stops_before_backup_or_write(self):
        remote, evidence, error = self.exercise_engine(baseline=b"drift")
        self.assertRegex(str(error), "production drift")
        self.assertEqual(remote.writes, [])
        self.assertEqual(evidence["status"], "FAILED")

    def test_existing_backup_stops_before_write(self):
        remote, evidence, error = self.exercise_engine(preexisting_backup=True)
        self.assertRegex(str(error), "Backup directory already exists")
        self.assertEqual(remote.writes, [])
        self.assertEqual(evidence["status"], "FAILED")

    def test_readback_mismatch_rolls_back_and_records_failure(self):
        remote, evidence, error = self.exercise_engine(corrupt=True)
        self.assertRegex(str(error), "Upload verification failed")
        self.assertEqual(remote.writes, [remote.target, remote.target])
        self.assertEqual(remote.files[remote.target], OLD)
        self.assertEqual(evidence["status"], "ROLLED_BACK")
        self.assertIsNone(evidence["files"][0]["readback_sha256"])

    def test_rollback_failure_is_not_reported_as_success(self):
        _, evidence, error = self.exercise_engine(corrupt=True, fail_rollback=True)
        self.assertIsNotNone(error)
        self.assertEqual(evidence["status"], "ROLLBACK_FAILED")

    def test_shared_connection_keeps_pinned_hostkey_and_disables_key_discovery(self):
        client = Mock()
        paramiko = SimpleNamespace(SSHClient=Mock(return_value=client), RejectPolicy=Mock(return_value=object()))
        settings = {"OVH_SFTP_HOST": "fixture.invalid", "OVH_SFTP_USER": "fixture",
                    "OVH_SFTP_PASSWORD": "synthetic", "OVH_SFTP_KNOWN_HOSTS": "synthetic pinned key",
                    "OVH_SFTP_BASE": "/fixture"}
        pinned = []
        client.load_host_keys.side_effect = lambda path: pinned.append(Path(path).read_text())
        with patch.dict(sys.modules, {"paramiko": paramiko}), patch.dict(os.environ, settings, clear=True):
            result, base = p.engine.connect()
        self.assertIs(result, client)
        self.assertEqual(base, "/fixture")
        self.assertEqual(pinned, ["synthetic pinned key\n"])
        client.set_missing_host_key_policy.assert_called_once_with(paramiko.RejectPolicy.return_value)
        client.connect.assert_called_once_with("fixture.invalid", port=22, username="fixture", password="synthetic",
                                               allow_agent=False, look_for_keys=False, timeout=20)


if __name__ == "__main__":
    unittest.main()
