"""Safety contract for exact-head premerge OVH manifest conversion."""
import hashlib
import tempfile
import unittest
from pathlib import Path
from scripts.premerge_shared_release_manifest import build_manifest

HEAD = "a" * 40
class PremergeManifestTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        p = self.root / "catalogus-public/admin/statistieken.php"
        p.parent.mkdir(parents=True)
        p.write_bytes(b"<?php echo 'ok';")
        self.path = "catalogus-public/admin/statistieken.php"
        self.digest = hashlib.sha256(p.read_bytes()).hexdigest()
        self.evidence = {"schema": 1, "head_sha": HEAD, "files": [{
            "path": self.path, "source_sha256": self.digest,
            "remote_sha256": "b" * 64
        }]}
    def tearDown(self):
        self.tmp.cleanup()
    def test_positive(self):
        obj = build_manifest(self.root, self.evidence, HEAD, [self.path])
        self.assertEqual(obj["files"][0]["source_sha256"], self.digest)
    def test_no_replay_on_new_pr_head(self):
        with self.assertRaises(ValueError):
            build_manifest(self.root, self.evidence, "c" * 40, [self.path])
    def test_no_source_change_after_scan(self):
        (self.root / self.path).write_bytes(b"changed")
        with self.assertRaises(ValueError):
            build_manifest(self.root, self.evidence, HEAD, [self.path])
    def test_no_extra_or_reordered_files(self):
        with self.assertRaises(ValueError):
            build_manifest(self.root, self.evidence, HEAD, [self.path, "catalogus-public/index.php"])
    def test_no_protected_paths(self):
        with self.assertRaises(ValueError):
            build_manifest(self.root, self.evidence, HEAD, ["private/visitor_stats_daily_cron.php"])
    def test_invalid_remote_hash(self):
        self.evidence["files"][0]["remote_sha256"] = "unknown"
        with self.assertRaises(ValueError):
            build_manifest(self.root, self.evidence, HEAD, [self.path])

if __name__ == "__main__":
    unittest.main()
