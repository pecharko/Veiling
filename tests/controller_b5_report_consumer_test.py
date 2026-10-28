#!/usr/bin/env python3
"""Offline contract tests for the non-authorizing B5 report consumer."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_b5_report_consumer import summarize

RUN = 40680179238
SHA = "44826d62bb34d9e6228f15826142e2c76a202243"
NAME = f"b5b-offline-consistency-{RUN}"


def report(status="VERIFIED", b4="VERIFIED", readback="VERIFIED"):
    return {"status": status, "b4_run_identity": {"status": b4},
            "readback_consistency": {"status": readback, "reason": "execution_commit_outside_authorization"},
            "independent_provenance_authenticated": False, "fully_accepted": False,
            "deployment_authorized": False, "dispatch_started": False,
            "browser_acceptance": "PENDING"}


class TestConsumer(unittest.TestCase):
    def check(self, value, expected):
        result = summarize(value, run_id=RUN, head_sha=SHA, artifact_name=NAME)
        self.assertEqual(result["status"], expected)
        self.assertFalse(result["deployment_authorized"])
        self.assertFalse(result["fully_accepted"])
        self.assertFalse(result["independent_provenance_authenticated"])
        self.assertEqual(result["browser_acceptance"], "PENDING")

    def test_verified_is_not_production_authority(self):
        self.check(report(), "VERIFIED")

    def test_b4_commit_mismatch_preserved(self):
        self.check(report("MISMATCH", "VERIFIED", "MISMATCH"), "MISMATCH")

    def test_nested_mismatch_cannot_be_upgraded(self):
        self.check(report("VERIFIED", "VERIFIED", "MISMATCH"), "MISMATCH")

    def test_missing_report(self):
        self.check(None, "INCOMPLETE")

    def test_incomplete(self):
        self.check(report("INCOMPLETE", "VERIFIED", "INCOMPLETE"), "INCOMPLETE")

    def test_untrusted_acceptance_refused(self):
        value = report()
        value["fully_accepted"] = True
        self.check(value, "INCOMPLETE")

    def test_invalid_identity_rejected(self):
        with self.assertRaises(ValueError):
            summarize(report(), run_id=RUN + 1, head_sha=SHA, artifact_name=NAME)
        with self.assertRaises(ValueError):
            summarize(report(), run_id=RUN, head_sha="bad", artifact_name=NAME)


if __name__ == "__main__":
    unittest.main()
