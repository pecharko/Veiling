#!/usr/bin/env python3
"""Offline smoke checks of read-only code capture and workflow invariants."""
import sys
import stat
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "scripts"))
from plan_catalog_recovery_release import FILES
from capture_recovery_code_backup import capture_readonly
from recovery_rollback_zip import verify_rollback_zip
from unittest.mock import patch

ROOT = "/example"
OLD = {name: None if i < 2 else b"old-contents-" + str(i).encode()
       for i, name in enumerate(FILES)}
BASELINE = {name: OLD[name] for name in FILES[2:]}


class FakeSftp:
    def __init__(self):
        self.files = {ROOT + "/" + name: data for name, data in OLD.items()
                      if data is not None}
        self.writes = 0

    def lstat(self, name):
        if name not in self.files:
            raise FileNotFoundError(name)
        class Stat:
            st_mode = stat.S_IFREG | 0o600
            st_size = 0
        info = Stat()
        info.st_size = len(self.files[name])
        return info

    def open(self, name, mode):
        if mode != "rb":
            self.writes += 1
            raise AssertionError("write attempted")
        from io import BytesIO
        return BytesIO(self.files[name])


class Tests(unittest.TestCase):
    def test_archive_has_six_files_and_zero_remote_writes(self):
        sftp = FakeSftp()
        report = {"status": "read_only_baseline_verified"}
        with patch("capture_recovery_code_backup.inspect_sftp_readonly", return_value=report):
            result = capture_readonly(sftp, ROOT, BASELINE, {})
        verify_rollback_zip(result, OLD)
        self.assertEqual(sftp.writes, 0)

    def test_refuse_drift_without_remote_writes(self):
        sftp = FakeSftp()
        sftp.files[ROOT + "/" + FILES[2]] = b"wrong"
        with patch("capture_recovery_code_backup.inspect_sftp_readonly",
                   return_value={"status": "read_only_baseline_verified"}):
            with self.assertRaises(Exception):
                capture_readonly(sftp, ROOT, BASELINE, {})
        self.assertEqual(sftp.writes, 0)

    def test_workflow_requires_manual_confirmation_and_no_upload(self):
        text = (Path(__file__).resolve().parent.parent /
                ".github/workflows/catalog-recovery-code-backup.yml").read_text()
        self.assertIn("workflow_dispatch:", text)
        self.assertIn("READ ONLY CODE BACKUP", text)
        self.assertNotIn("scripts/recovery_release_transaction.py", text)
        self.assertNotIn("mode: deploy", text)
        self.assertIn("retention-days: 7", text)

if __name__ == "__main__":
    unittest.main()
