#!/usr/bin/env python3
"""Persisted rollback content verification tests, offline only."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from plan_catalog_recovery_release import FILES
from recovery_rollback_zip import make_rollback_zip
from verify_persisted_recovery_backup import verify_downloaded_code_backup


class VerifiedArchiveTests(unittest.TestCase):
    def setUp(self):
        self.baseline = {p: (p + " original").encode() for p in FILES[2:]}
        self.original = {FILES[0]: None, FILES[1]: None, **self.baseline}

    def test_exact_archive_passes(self):
        verify_downloaded_code_backup(make_rollback_zip(self.original), self.baseline)

    def test_other_release_archive_rejected(self):
        modified = dict(self.original)
        modified[FILES[3]] = b"unexpected remote version"
        with self.assertRaisesRegex(RuntimeError, "differs"):
            verify_downloaded_code_backup(make_rollback_zip(modified), self.baseline)

    def test_empty_archive_rejected(self):
        with self.assertRaises(ValueError):
            verify_downloaded_code_backup(b"", self.baseline)


if __name__ == "__main__":
    unittest.main()
