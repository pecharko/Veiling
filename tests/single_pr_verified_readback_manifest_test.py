"""Offline single-PR manifest conversion, never an authorization oracle."""
import hashlib
import tempfile
import unittest
from pathlib import Path
from scripts.single_pr_verified_readback_manifest import from_verified_log, verified_sha

class SinglePRReadbackTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.path = "catalogus-public/admin/statistieken.php"
        file = self.root / self.path
        file.parent.mkdir(parents=True)
        file.write_bytes(b"<?php echo 1;")
        self.digest = hashlib.sha256(file.read_bytes()).hexdigest()
        self.log = "2026-11-01T07:28:39.8503636Z VERIFIED " + self.path + " " + "a" * 64
    def tearDown(self):
        self.temp.cleanup()
    def test_generates_exact_standard_manifest(self):
        m = from_verified_log(self.root, [self.path], self.log)
        self.assertEqual(m["files"][0], {"path": self.path,
                          "source_sha256": self.digest, "remote_sha256": "a" * 64})
    def test_rejects_missing_readback(self):
        with self.assertRaises(ValueError):
            from_verified_log(self.root, [self.path], "UPLOAD VERIFIED")
    def test_rejects_duplicate_readback(self):
        with self.assertRaises(ValueError):
            from_verified_log(self.root, [self.path], self.log + "\n" + self.log)
    def test_rejects_protected_path(self):
        with self.assertRaises(ValueError):
            from_verified_log(self.root, ["private/visitor_stats_daily_cron.php"], self.log)
    def test_rejects_duplicate_paths(self):
        with self.assertRaises(ValueError):
            from_verified_log(self.root, [self.path, self.path], self.log)
    def test_rejects_invalid_hash(self):
        with self.assertRaises(ValueError):
            verified_sha("VERIFIED " + self.path + " nohash", self.path)
    def test_rejects_changed_log_path(self):
        with self.assertRaises(ValueError):
            verified_sha(self.log.replace(self.path, "catalogus-public/index.php"), self.path)

if __name__ == "__main__":
    unittest.main()
