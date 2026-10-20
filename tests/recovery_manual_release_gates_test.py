#!/usr/bin/env python3
"""Offline tests of the minimum release evidence gate."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "scripts"))
from recovery_manual_release_gates import REQUIRED, assess_release_gate

SHA = "a" * 40

class GateTests(unittest.TestCase):
    def test_missing_evidence_blocks_readiness(self):
        result = assess_release_gate({"release_sha": SHA}, SHA)
        self.assertEqual(len(result["missing_checks"]), len(REQUIRED))
        self.assertFalse(result["upload_authorized"])
        self.assertFalse(result["review_complete"])

    def test_all_attestations_still_never_authorize_upload(self):
        evidence = {"release_sha": SHA, **{k: True for k in REQUIRED}}
        result = assess_release_gate(evidence, SHA)
        self.assertTrue(result["review_complete"])
        self.assertFalse(result["upload_authorized"])

    def test_wrong_sha_rejected(self):
        with self.assertRaises(ValueError):
            assess_release_gate({"release_sha": "b" * 40}, SHA)

    def test_truthy_string_does_not_pass(self):
        evidence = {"release_sha": SHA, **{k: True for k in REQUIRED}}
        evidence["verified_private_code_backup_persisted"] = "true"
        self.assertIn("verified_private_code_backup_persisted",
                      assess_release_gate(evidence, SHA)["missing_checks"])

    def test_unknown_field_rejected(self):
        with self.assertRaises(ValueError):
            assess_release_gate({"release_sha": SHA, "override_upload": True}, SHA)

if __name__ == "__main__":
    unittest.main()
