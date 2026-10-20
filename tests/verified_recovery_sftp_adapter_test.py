#!/usr/bin/env python3
"""Offline boundary tests; no OVH connection or production secrets."""
import sys
import unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))

from plan_catalog_recovery_release import FILES
from preflight_catalog_recovery_remote import EXISTING
from verified_recovery_sftp_adapter import ReleaseBlocked, upload_after_remote_recheck


class AdapterTests(unittest.TestCase):
    def setUp(self):
        self.manifest = {"files": [{"path": p} for p in FILES]}
        self.baseline = {p: b"old" for p in EXISTING}
        self.replacements = {p: b"new" for p in FILES}

    @patch("verified_recovery_sftp_adapter.upload_with_verified_archive")
    @patch("verified_recovery_sftp_adapter.inspect_sftp_readonly")
    def test_preflight_must_pass_before_uploader(self, inspect, upload):
        inspect.return_value = {"status": "drift"}
        with self.assertRaises(ReleaseBlocked):
            upload_after_remote_recheck(object(), "/root", self.manifest,
                                        self.baseline, self.replacements, b"archive")
        upload.assert_not_called()

    @patch("verified_recovery_sftp_adapter.upload_with_verified_archive")
    @patch("verified_recovery_sftp_adapter.inspect_sftp_readonly")
    def test_rechecks_then_passes_exact_eight_to_guarded_engine(self, inspect, upload):
        inspect.return_value = {"status": "read_only_baseline_verified"}
        upload.return_value = list(FILES)
        value = upload_after_remote_recheck(object(), "/root", self.manifest,
                                            self.baseline, self.replacements, b"archive")
        self.assertEqual(value, list(FILES))
        inspect.assert_called_once()
        upload.assert_called_once()
        original = upload.call_args.args[2]
        self.assertEqual(set(original), set(FILES))
        self.assertEqual(sum(x is None for x in original.values()), 2)

    @patch("verified_recovery_sftp_adapter.inspect_sftp_readonly")
    def test_unexpected_path_blocks_before_network_io(self, inspect):
        modified = dict(self.replacements)
        modified["private/config.php"] = b"no"
        with self.assertRaises(ReleaseBlocked):
            upload_after_remote_recheck(object(), "/root", self.manifest,
                                        self.baseline, modified, b"archive")
        inspect.assert_not_called()


if __name__ == "__main__":
    unittest.main()
