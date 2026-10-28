import os
import unittest
from unittest.mock import patch
from scripts import deploy_snyk_snapshot_delete as d

class ProtectedSnapshotReleaseTests(unittest.TestCase):
    def test_exact_scope_and_protected_order(self):
        self.assertEqual(d.FILES, ("private/snapshots.php", "catalogus-public/admin/backup.php"))

    def test_plan_without_secrets_or_sftp(self):
        with patch.object(d.engine, "connect", side_effect=AssertionError("unexpected SFTP")):
            with patch.object(d.sys, "argv", ["x", "plan"]):
                d.main()

    def test_fail_closed_missing_baselines(self):
        for values in ({}, {"EXPECTED_SNAPSHOT_SHA": "x"*64},
                       {"EXPECTED_SNAPSHOT_SHA": "a"*64, "EXPECTED_BACKUP_SHA": "invalid"}):
            with self.subTest(values=values), patch.dict(os.environ, values, clear=True):
                with self.assertRaises(ValueError):
                    d.guarded_load(d.RELEASE_ID)

    def test_approved_baseline_is_bound_to_exact_paths(self):
        vals = {"EXPECTED_SNAPSHOT_SHA": "a"*64, "EXPECTED_BACKUP_SHA": "b"*64}
        with patch.dict(os.environ, vals, clear=True):
            manifest, source, hashes = d.guarded_load(d.RELEASE_ID)
            self.assertEqual(list(source), list(d.FILES))
            self.assertEqual(list(hashes), list(d.FILES))
            self.assertEqual(manifest["files"][0]["remote_sha256"], "a"*64)
            self.assertEqual(manifest["files"][1]["remote_sha256"], "b"*64)

    def test_deploy_requires_exact_user_approval_before_network(self):
        with patch.dict(os.environ, {"GITHUB_SHA": "a"*40, "DEPLOY_CONFIRMATION": "go"}, clear=True):
            with patch.object(d.engine, "connect", side_effect=AssertionError("unexpected SFTP")):
                with patch.object(d.sys, "argv", ["x", "deploy"]):
                    with self.assertRaises(ValueError):
                        d.main()

if __name__ == "__main__":
    unittest.main()
