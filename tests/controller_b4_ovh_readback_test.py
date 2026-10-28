"""B4 read-only OVH observation tests; never initiate network."""
import sys
import unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
import controller_b4_ovh_readback as module

SHA = "a" * 40
DATA = b"checked content"
MANIFEST = {"schema": 1, "files": [
    {"path": "catalogus-public/admin/admin.css",
     "source_sha256": module.digest(DATA), "remote_sha256": "ABSENT"}]}


class B4OVHReadbackTests(unittest.TestCase):
    def test_observation_is_not_acceptance(self):
        with patch.object(module, "load_manifest", return_value=(MANIFEST, {}, {})):
            result = module.verify("review-demo", SHA, 31, read=lambda _: DATA, checkout_check=lambda: SHA)
        self.assertEqual(result["status"], "OBSERVED_MATCH")
        self.assertFalse(result["writes_performed"])
        self.assertFalse(result["independent_execution_authenticated"])
        self.assertEqual(result["browser_acceptance"], "PENDING")

    def test_mismatch_fails(self):
        with patch.object(module, "load_manifest", return_value=(MANIFEST, {}, {})):
            for raw in (b"different", None):
                with self.subTest(raw=raw):
                    result = module.verify("review-demo", SHA, 31, read=lambda _: raw, checkout_check=lambda: SHA)
                    self.assertEqual(result["status"], "MISMATCH")

    def test_invalid_identity_skips_manifest(self):
        for sha, run in (("bad", 31), (SHA, 0), (SHA, True)):
            with patch.object(module, "load_manifest",
                              side_effect=AssertionError("manifest accessed")):
                with self.assertRaises(ValueError):
                    module.verify("review-demo", sha, run, read=lambda _: DATA, checkout_check=lambda: SHA)

    def test_wrong_checkout_blocks_before_manifest_or_ovh(self):
        with patch.object(module, "load_manifest", side_effect=AssertionError("manifest accessed")):
            with self.assertRaisesRegex(ValueError, "unpinned"):
                module.verify("review-demo", SHA, 31,
                              read=lambda _: self.fail("remote read started"),
                              checkout_check=lambda: "b" * 40)

    def test_checkout_requires_clean_tree(self):
        from types import SimpleNamespace
        with patch.object(module.subprocess, "run", side_effect=[
            SimpleNamespace(stdout=SHA + "\\n"),
            SimpleNamespace(stdout=" M releases/demo.json\\n")
        ]):
            with self.assertRaisesRegex(ValueError, "clean pinned"):
                module.check_checkout()

    def test_reader_failure_does_not_report_success(self):
        with patch.object(module, "load_manifest", return_value=(MANIFEST, {}, {})):
            with self.assertRaises(OSError):
                module.verify("review-demo", SHA, 31,
                              read=lambda _: (_ for _ in ()).throw(OSError("remote failed")), checkout_check=lambda: SHA)


if __name__ == "__main__":
    unittest.main()
