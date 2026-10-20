#!/usr/bin/env python3
"""Offline backup evidence tests: no remote access and no write operations."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "scripts"))
from plan_catalog_recovery_release import FILES
from recovery_backup_evidence import (
    code_backup_manifest, verify_code_backup, verify_external_data_evidence
)

ORIGINAL = {name: None if i < 2 else ("old-" + str(i)).encode()
            for i, name in enumerate(FILES)}
SAVED = {k: v for k, v in ORIGINAL.items() if v is not None}
DATA = {"kind": "41lokeren-external-data-only-evidence-v1",
        "offsite_verified_by_operator": True,
        "independent_copy_confirmed": True,
        "size_bytes": 100, "sha256": "a" * 64}

class BackupTests(unittest.TestCase):
    def test_six_saved_two_absent(self):
        manifest = code_backup_manifest(ORIGINAL)
        self.assertEqual(len(manifest["entries"]), 8)
        self.assertFalse(manifest["upload_allowed"])
        verify_code_backup(manifest, SAVED)

    def test_tampering_detected(self):
        manifest = code_backup_manifest(ORIGINAL)
        altered = dict(SAVED)
        altered[FILES[2]] = b"altered"
        with self.assertRaises(ValueError):
            verify_code_backup(manifest, altered)

    def test_missing_file_detected(self):
        with self.assertRaises(ValueError):
            verify_code_backup(code_backup_manifest(ORIGINAL),
                               {k:v for k,v in SAVED.items() if k != FILES[2]})

    def test_new_helper_not_assumed_absent(self):
        modified = dict(ORIGINAL)
        modified[FILES[0]] = b"already present"
        with self.assertRaises(ValueError):
            code_backup_manifest(modified)

    def test_external_attestation_requires_fields(self):
        verify_external_data_evidence(DATA)
        for key in ("independent_copy_confirmed", "sha256", "size_bytes"):
            incomplete = dict(DATA)
            incomplete.pop(key)
            with self.assertRaises(ValueError):
                verify_external_data_evidence(incomplete)

if __name__ == "__main__":
    unittest.main()
