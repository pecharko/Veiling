#!/usr/bin/env python3
"""Fault tests for disconnected minimum-safe uploader; no network."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "scripts"))
from plan_catalog_recovery_release import FILES, sha256
from recovery_rollback_zip import make_rollback_zip
from minimum_safe_recovery_uploader import upload_with_verified_archive
from recovery_release_transaction_test import FakeSftp, ROOT, OLD, NEW, FILES_ON_SERVER

MANIFEST = {"release_sha": "a" * 40, "files": [
    {"path": p, "sha256": sha256(NEW[p]), "php_lint": "pass"} for p in FILES
]}

class Tests(unittest.TestCase):
    def test_verified_archive_allows_offline_simulation(self):
        sftp = FakeSftp(FILES_ON_SERVER)
        touched = upload_with_verified_archive(sftp, ROOT, OLD, NEW, MANIFEST,
                                               make_rollback_zip(OLD))
        self.assertEqual(touched, list(FILES))

    def test_missing_archive_blocks_writes(self):
        sftp = FakeSftp(FILES_ON_SERVER)
        with self.assertRaises(ValueError):
            upload_with_verified_archive(sftp, ROOT, OLD, NEW, MANIFEST, b"")
        self.assertEqual(sftp.writes, [])

    def test_source_mismatch_blocks_writes(self):
        sftp = FakeSftp(FILES_ON_SERVER)
        changed = dict(NEW)
        changed[FILES[7]] = b"tampered"
        with self.assertRaisesRegex(ValueError, "Source manifest drift"):
            upload_with_verified_archive(sftp, ROOT, OLD, changed, MANIFEST,
                                         make_rollback_zip(OLD))
        self.assertEqual(sftp.writes, [])

    def test_partial_upload_rolls_back(self):
        sftp = FakeSftp(FILES_ON_SERVER, ROOT + "/" + FILES[3])
        with self.assertRaisesRegex(RuntimeError, "rollback verified"):
            upload_with_verified_archive(sftp, ROOT, OLD, NEW, MANIFEST,
                                         make_rollback_zip(OLD))
        self.assertEqual(sftp.files, FILES_ON_SERVER)

if __name__ == "__main__":
    unittest.main()
