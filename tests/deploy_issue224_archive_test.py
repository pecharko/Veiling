"""Exercise issue224 upload/rollback ordering with an in-memory SFTP boundary."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "issue224_archive_release", ROOT / "scripts/deploy_issue224_archive.py")
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)

ADMIN = "catalogus-public/admin/statistieken.php"
PRIVACY = "catalogus-public/privacy.php"
HELPER = "private/visitor_stats_archive_one_day_experimental.php"
CRON = "private/visitor_stats_daily_cron.php"


class MemorySFTP:
    def __init__(self, prior):
        self.files = dict(prior)
        self.writes = []
        self.snapshots = []
        self.fail_once = None

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return False

    def open(self, path, mode):
        assert path.startswith("/fixture/") and mode == "wb"
        name = path.removeprefix("/fixture/")
        assert name in self.files
        if self.fail_once == name:
            self.fail_once = None
            raise OSError("simulated upload failure")
        sftp = self

        class Writer:
            def __enter__(self):
                return self

            def __exit__(self, *args):
                return False

            def write(self, data):
                sftp.files[name] = data
                sftp.writes.append(name)
                sftp.snapshots.append(dict(sftp.files))

            def flush(self):
                pass

        return Writer()


class Issue224ReleaseOrderTests(unittest.TestCase):
    def setUp(self):
        self.prior = {name: ("old:" + name).encode()
                      for name in (ADMIN, PRIVACY, HELPER, CRON)}
        self.payload = {name: ("new:" + name).encode() for name in self.prior}
        self.payload[PRIVACY] = self.prior[PRIVACY]
        self.sftp = MemorySFTP(self.prior)
        self.client = Mock()
        self.client.open_sftp.return_value = self.sftp
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.backup = Path(temp.name) / "backup"
        registry = json.loads((ROOT / "scripts/verified_deploy_registry.json").read_text())
        self.registered_paths = registry["releases"][
            "issue-224-archive-code-release.yml"]["paths"]
        expected = ",".join(release.sha(self.prior[name])
                            for name in self.registered_paths)
        for replacement in (
            patch.object(release, "source", return_value=self.payload),
            patch.object(release, "connect", return_value=(self.client, "/fixture")),
            patch.object(release, "inspect", side_effect=lambda *_: dict(self.sftp.files)),
            patch.object(release, "remote_read", side_effect=lambda _, path, *args:
                         self.sftp.files[path.removeprefix("/fixture/")]),
            patch.object(release, "BACKUP", self.backup),
            patch.dict(release.os.environ, {"EXPECTED_REMOTE_HASHES": expected}),
            patch.object(release, "print", create=True),
        ):
            replacement.start()
            self.addCleanup(replacement.stop)

    def assert_dependencies_available(self):
        for state in self.sftp.snapshots:
            for caller in (ADMIN, CRON):
                if state[caller] == self.payload[caller]:
                    self.assertEqual(state[HELPER], self.payload[HELPER],
                                     "A new caller was exposed without its helper")

    def test_upload_keeps_registered_hash_order_and_publishes_helper_first(self):
        self.assertEqual(release.FILES, tuple(self.registered_paths))
        self.assertCountEqual(release.DEPLOY_ORDER, self.registered_paths)
        release.deploy()
        self.assert_dependencies_available()
        self.assertEqual(self.sftp.writes, [HELPER, ADMIN, CRON])
        self.assertEqual(self.sftp.files, self.payload)
        for name, data in self.prior.items():
            self.assertEqual((self.backup / name).read_bytes(), data)
        self.client.close.assert_called_once()

    def test_late_failure_restores_callers_before_removing_new_helper(self):
        self.sftp.fail_once = CRON
        with self.assertRaisesRegex(OSError, "simulated upload failure"):
            release.deploy()
        self.assertEqual(self.sftp.files, self.prior)
        self.assert_dependencies_available()
        self.assertEqual(self.sftp.writes, [HELPER, ADMIN, CRON, ADMIN, HELPER])
        self.client.close.assert_called_once()

    def test_helper_failure_never_publishes_a_dependent_caller(self):
        self.sftp.fail_once = HELPER
        with self.assertRaisesRegex(OSError, "simulated upload failure"):
            release.deploy()
        self.assertEqual(self.sftp.files, self.prior)
        self.assertEqual(self.sftp.writes, [HELPER])
        self.assert_dependencies_available()

    def test_remote_drift_stops_before_any_upload(self):
        self.sftp.files[PRIVACY] = b"changed after preflight"
        with self.assertRaisesRegex(RuntimeError, "remote hash drift"):
            release.deploy()
        self.assertEqual(self.sftp.writes, [])
        self.assertFalse(self.backup.exists())
        self.client.close.assert_called_once()


if __name__ == "__main__":
    unittest.main()
