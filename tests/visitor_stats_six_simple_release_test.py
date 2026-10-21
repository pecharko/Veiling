#!/usr/bin/env python3
"""Security regression tests for visitor-stats no-rollback release."""
import hashlib
import re
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]/"scripts"))
import deploy_visitor_stats_six_simple as d
from visitor_stats_six_remote_preflight_test import FakeSFTP, ROOT, OLD, manifest
from visitor_stats_retention_test import RetentionTests  # included in workflow unittest.main()
from visitor_stats_admin_deletion_test import AnalyticsAdminDeletionTests  # integrated security regressions

class Tests(unittest.TestCase):
    def baseline(self):
        return {x: hashlib.sha256(OLD[x]).hexdigest() for x in d.EXISTING}

    def test_preflight_matches_known_existing_not_planned_hash(self):
        fake=FakeSFTP()
        with patch.object(d, "OBSERVED", self.baseline()):
            report=d.assert_preflight(fake, ROOT, manifest())
        self.assertTrue(report["expected_new_files_absent"])
        self.assertFalse(report["remote_baseline_verified"])
        self.assertEqual({x[0] for x in fake.calls},{"lstat","listdir","open"})

    def test_modified_remote_fails_before_upload(self):
        fake=FakeSFTP()
        with self.assertRaises(d.PreflightStop):
            d.assert_preflight(fake,ROOT,manifest())
        self.assertEqual({x[0] for x in fake.calls},{"lstat","listdir","open"})

    def test_source_allowlist_order_enforced(self):
        fake=FakeSFTP()
        with self.assertRaises(d.PreflightStop):
            d.upload_no_rollback(fake,ROOT,manifest(),{})
        self.assertEqual(fake.calls,[])

    def test_no_rollback_apis_or_secrets(self):
        code=Path(d.__file__).read_text()
        for name in ("rollback", "restore"):
            self.assertIsNone(re.search(r"(?<![A-Za-z0-9_])" + name + r"\s*\(", code))
        for forbidden in ("private/config.php", "sftp.remove(", "sftp.rename(", "sftp.put("):
            self.assertNotIn(forbidden, code)

    def test_new_sftp_file_is_exclusive_and_writable(self):
        code = Path(d.__file__).read_text()
        self.assertIn('mode = "wxb" if name in NEW else "wb"', code)
        self.assertNotIn('mode = "xb" if name in NEW', code)

    def test_six_file_scope_includes_privacy(self):
        self.assertEqual(len(d.FILES), 6)
        self.assertIn("catalogus-public/privacy.php", d.NEW)
        self.assertEqual(set(d.EXISTING), {"private/bootstrap.php", "catalogus-public/index.php", "catalogus-public/kavel.php"})

    def test_rollback_call_detection_does_not_match_upload_no_rollback(self):
        pattern = r"(?<![A-Za-z0-9_])rollback\s*\("
        self.assertIsNone(re.search(pattern, "upload_no_rollback(sftp, root, manifest, files)"))
        self.assertIsNotNone(re.search(pattern, "rollback(sftp, root)"))

if __name__=="__main__":
    unittest.main()
