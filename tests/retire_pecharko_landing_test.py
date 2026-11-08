"""Isolated, offline guard tests for one-off legacy Pecharko file deletion."""
import io
import json
import os
from pathlib import Path
import stat
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch
from types import SimpleNamespace

from scripts import retire_pecharko_landing as retire


class BufferedWriter(io.BytesIO):
    def __init__(self, data, sink):
        super().__init__(data)
        self.sink = sink
    def close(self):
        self.sink(self.getvalue())
        super().close()


class FakeSftp:
    def __init__(self, fail_on=None):
        self.files = {"/fixture/catalogus-public/admin/index.php": b"dashboard-table"}
        self.files.update({"/fixture/" + name: ("original " + name).encode()
                           for name in retire.TARGETS})
        self.fail_on = fail_on
        self.writes = 0
        self.deletions = []
    def __enter__(self):
        return self
    def __exit__(self, *args):
        return False
    def lstat(self, path):
        if path not in self.files:
            raise FileNotFoundError(2, "absent")
        return SimpleNamespace(st_mode=stat.S_IFREG | 0o600, st_size=len(self.files[path]))
    def open(self, path, mode):
        if mode == "rb":
            return io.BytesIO(self.files[path])
        if mode != "wb":
            raise AssertionError("unexpected SFTP mode")
        self.writes += 1
        return BufferedWriter(b"", lambda data: self.files.__setitem__(path, data))
    def remove(self, path):
        if self.fail_on == path:
            raise RuntimeError("synthetic second-file deletion failure")
        self.deletions.append(path)
        del self.files[path]


class RetireTests(unittest.TestCase):
    def setup_sftp(self, fail_on=None):
        sftp = FakeSftp(fail_on=fail_on)
        client = SimpleNamespace(open_sftp=lambda: sftp, close=lambda: None)
        return sftp, lambda: (client, "/fixture")
    def test_exact_two_targets_and_no_arbitrary_path(self):
        self.assertEqual(retire.TARGETS, (
            "catalogus-public/pecharko.php",
            "catalogus-public/assets/pecharko.css"))
        self.assertNotIn("private/config.php", retire.TARGETS)
        self.assertNotIn("catalogus-public/index.php", retire.TARGETS)

    def test_missing_owner_confirmation_denied_before_sftp(self):
        with patch.dict(os.environ, {"GITHUB_SHA": "a"*40, "RETIRE_CONFIRMATION": ""},
                        clear=True), self.assertRaisesRegex(ValueError, "authorization missing"):
            retire.run_deploy(connect=lambda: (_ for _ in ()).throw(AssertionError("No SFTP")))

    def test_readonly_preflight_has_zero_remote_mutations(self):
        sftp, connect = self.setup_sftp()
        with TemporaryDirectory() as tmp, patch.object(retire, "ROOT", Path(tmp)):
            result = retire.run_preflight(connect)
            self.assertEqual(list(result), list(retire.TARGETS))
            self.assertTrue(all(len(x) == 64 for x in result.values()))
            self.assertEqual(sftp.deletions, [])
            self.assertEqual(sftp.writes, 0)

    def test_drift_aborts_before_any_deletion_or_backup(self):
        sftp, connect = self.setup_sftp()
        values = {name: retire.engine.digest(sftp.files["/fixture/" + name])
                  for name in retire.TARGETS}
        with TemporaryDirectory() as tmp, patch.object(retire, "EVIDENCE", Path(tmp)/"e.json"), \
             patch.object(retire, "BACKUPS", Path(tmp)/"bk"), patch.dict(os.environ, {
                 "GITHUB_SHA": "a"*40, "RETIRE_CONFIRMATION": "DELETE-PECHARKO-LANDING-20261108-" + "a"*40,
                 "EXPECTED_PECHARKO_PHP_SHA": "b"*64,
                 "EXPECTED_PECHARKO_CSS_SHA": values[retire.TARGETS[1]],
             }):
            with self.assertRaisesRegex(RuntimeError, "production drift"):
                retire.run_deploy(connect)
            self.assertEqual(sftp.deletions, [])
            self.assertEqual(sftp.writes, 0)

    def test_success_requires_two_absent_readbacks(self):
        sftp, connect = self.setup_sftp()
        values = {name: retire.engine.digest(sftp.files["/fixture/" + name])
                  for name in retire.TARGETS}
        with TemporaryDirectory() as tmp, patch.object(retire, "EVIDENCE", Path(tmp)/"e.json"), \
             patch.object(retire, "BACKUPS", Path(tmp)/"bk"), patch.dict(os.environ, {
                 "GITHUB_SHA": "a"*40, "RETIRE_CONFIRMATION": "DELETE-PECHARKO-LANDING-20261108-" + "a"*40,
                 "EXPECTED_PECHARKO_PHP_SHA": values[retire.TARGETS[0]],
                 "EXPECTED_PECHARKO_CSS_SHA": values[retire.TARGETS[1]],
             }):
            retire.run_deploy(connect)
            self.assertEqual(len(sftp.deletions), 2)
            self.assertEqual(sftp.writes, 0)
            self.assertTrue(all("/fixture/"+name not in sftp.files for name in retire.TARGETS))
            self.assertEqual(json.loads((Path(tmp)/"e.json").read_text())["status"], "VERIFIED")

    def test_second_delete_failure_rolls_back_first_file(self):
        sftp, connect = self.setup_sftp(fail_on="/fixture/"+retire.TARGETS[1])
        old = dict(sftp.files)
        values = {name: retire.engine.digest(old["/fixture/" + name])
                  for name in retire.TARGETS}
        with TemporaryDirectory() as tmp, patch.object(retire, "EVIDENCE", Path(tmp)/"e.json"), \
             patch.object(retire, "BACKUPS", Path(tmp)/"bk"), patch.dict(os.environ, {
                 "GITHUB_SHA": "a"*40, "RETIRE_CONFIRMATION": "DELETE-PECHARKO-LANDING-20261108-" + "a"*40,
                 "EXPECTED_PECHARKO_PHP_SHA": values[retire.TARGETS[0]],
                 "EXPECTED_PECHARKO_CSS_SHA": values[retire.TARGETS[1]],
             }):
            with self.assertRaisesRegex(RuntimeError, "second-file"):
                retire.run_deploy(connect)
            self.assertEqual(sftp.files, old)
            e = json.loads((Path(tmp)/"e.json").read_text())
            self.assertEqual(e["status"], "FAILED")
            self.assertEqual(e["rollback"], "VERIFIED")


if __name__ == "__main__":
    unittest.main()
