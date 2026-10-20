#!/usr/bin/env python3
"""No-network rollback ZIP roundtrip tests."""
import io
import sys
import unittest
import zipfile
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "scripts"))
from plan_catalog_recovery_release import FILES
from recovery_rollback_zip import make_rollback_zip, verify_rollback_zip

OLD = {name: None if i < 2 else ("old-" + str(i)).encode()
       for i, name in enumerate(FILES)}

class RollbackZipTests(unittest.TestCase):
    def test_roundtrip(self):
        body = make_rollback_zip(OLD)
        verify_rollback_zip(body, OLD)

    def test_wrong_original_rejected(self):
        body = make_rollback_zip(OLD)
        edited = dict(OLD)
        edited[FILES[2]] = b"altered"
        with self.assertRaises(ValueError):
            verify_rollback_zip(body, edited)

    def test_reject_extra_archive_file(self):
        body = io.BytesIO(make_rollback_zip(OLD))
        with zipfile.ZipFile(body, "a") as archive:
            archive.writestr("private/config.php", b"forbidden")
        with self.assertRaisesRegex(ValueError, "Unsafe archive entries"):
            verify_rollback_zip(body.getvalue(), OLD)

    def test_reject_missing_original(self):
        changed = dict(OLD)
        changed[FILES[3]] = None
        with self.assertRaises(ValueError):
            make_rollback_zip(changed)

if __name__ == "__main__":
    unittest.main()
