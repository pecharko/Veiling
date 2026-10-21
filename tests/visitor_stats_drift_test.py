#!/usr/bin/env python3
"""T-063 isolated regression tests for read-only evidence comparison."""
import hashlib
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
import compare_visitor_stats_drift as module

SHA = "a" * 40
class Tests(unittest.TestCase):
    def test_history_matches_with_exact_bytes_only(self):
        path = next(iter(module.OBSERVED))
        correct = b"known example"
        digest = hashlib.sha256(correct).hexdigest()
        def fake_git(*args):
            if args[0] == "rev-parse": return SHA.encode()
            if args[0] == "merge-base": return b""
            if args[0] == "rev-list": return ("b"*40+"\n"+"c"*40+"\n").encode()
            if args[0] == "show": return correct if args[1].startswith("b") else b"different"
            raise AssertionError(args)
        with patch.object(module, "OBSERVED", {path:digest}), patch.object(module, "git", side_effect=fake_git):
            report = module.compare_history(SHA)
        entry = report["entries"][0]
        self.assertTrue(entry["historical_match_found"])
        self.assertEqual(entry["matching_historical_commits"], ["b"*40])
        self.assertFalse(report["production_baseline_verified"])
        self.assertFalse(report["deploy_approved"])
        self.assertFalse(report["remote_rechecked"])

    def test_no_match_is_never_approved(self):
        def fake_git(*args):
            if args[0] == "rev-parse": return SHA.encode()
            if args[0] == "merge-base": return b""
            if args[0] == "rev-list": return ("b"*40+"\n").encode()
            if args[0] == "show": return b"wrong"
            raise AssertionError(args)
        with patch.object(module, "git", side_effect=fake_git):
            report = module.compare_history(SHA)
        self.assertTrue(all(not row["historical_match_found"] for row in report["entries"]))
        self.assertFalse(report["release_approved"])

    def test_wrong_commit_rejected(self):
        with self.assertRaises(ValueError): module.compare_history("invalid")
        with patch.object(module, "git", return_value=b"b"*40):
            with self.assertRaises(ValueError): module.compare_history(SHA)

    def test_no_network_or_mutation_apis(self):
        source = Path(module.__file__).read_text()
        for forbidden in ("paramiko", "open_sftp(", "requests.", "urllib.request", "sftp.put(", "DROP TABLE", "CREATE TABLE", "os.remove(", "unlink("):
            self.assertNotIn(forbidden, source)

if __name__ == "__main__":
    unittest.main()
