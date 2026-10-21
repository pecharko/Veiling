#!/usr/bin/env python3
"""T-064 rollback ZIP and fake SFTP safety tests (no OVH access)."""
import hashlib
import io
import sys
import unittest
import zipfile
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
import capture_visitor_stats_code_backup as b
from visitor_stats_remote_preflight_test import FakeSFTP, ROOT, OLD, manifest

SHA = "a" * 40
class BackupTests(unittest.TestCase):
    def baseline(self):
        return {name: hashlib.sha256(OLD[name]).hexdigest() for name in b.EXISTING}

    def test_readonly_zip_roundtrip(self):
        sftp = FakeSFTP()
        with patch.object(b, "OBSERVED", self.baseline()):
            archive = b.capture(sftp, ROOT, manifest(), "12345")
            b.verify_zip(archive, {x: OLD[x] for x in b.EXISTING}, SHA)
        with zipfile.ZipFile(io.BytesIO(archive)) as z:
            self.assertEqual(len(z.namelist()), 4)
            self.assertTrue(all("config.php" not in p for p in z.namelist()))
        self.assertEqual({x[0] for x in sftp.calls}, {"lstat", "listdir", "open"})
        self.assertTrue(all(x[2] == "rb" for x in sftp.calls if x[0] == "open"))

    def test_changed_baseline_fails_closed(self):
        sftp = FakeSFTP()
        with self.assertRaises(b.PreflightStop):
            b.capture(sftp, ROOT, manifest(), "12345")

    def test_active_restore_fails(self):
        sftp = FakeSFTP()
        sftp.names[ROOT + "/private/backups"].append(".restore-active.json")
        with patch.object(b, "OBSERVED", self.baseline()):
            with self.assertRaises(b.PreflightStop):
                b.capture(sftp, ROOT, manifest(), "12345")

    def test_new_file_fails(self):
        sftp = FakeSFTP()
        sftp.data[ROOT + "/private/visitor_stats.php"] = b"x"
        with patch.object(b, "OBSERVED", self.baseline()):
            with self.assertRaises(b.PreflightStop):
                b.capture(sftp, ROOT, manifest(), "12345")

    def test_corrupt_zip_fails(self):
        originals = {name: OLD[name] for name in b.EXISTING}
        with patch.object(b, "OBSERVED", self.baseline()):
            body = b.make_zip(originals, SHA, "12345")
            with self.assertRaises((zipfile.BadZipFile, b.PreflightStop, ValueError)):
                b.verify_zip(body[:30], originals, SHA)

if __name__ == "__main__":
    unittest.main()
