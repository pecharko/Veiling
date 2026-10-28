#!/usr/bin/env python3
"""Offline assurance: advisory B5 status cannot authorize a deployment."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from verified_deploy_controller import inspect_b5_advisory_report

RUN = 40680179238
SHA = "44826d62bb34d9e6228f15826142e2c76a202243"
NAME = f"b5b-offline-consistency-{RUN}"


def sample(status, readback):
    return {
        "status": status,
        "b4_run_identity": {"status": "VERIFIED"},
        "readback_consistency": {"status": readback, "reason": "execution_commit_outside_authorization"},
        "independent_provenance_authenticated": False,
        "fully_accepted": False,
        "deployment_authorized": False,
        "dispatch_started": False,
        "browser_acceptance": "PENDING",
    }


class TestAdvisoryReview(unittest.TestCase):
    def check(self, report, status):
        result = inspect_b5_advisory_report(report, run_id=RUN, head_sha=SHA,
                                           artifact_name=NAME)
        self.assertEqual(result["status"], status)
        self.assertFalse(result["deployment_authorized"])
        self.assertFalse(result["fully_accepted"])
        self.assertFalse(result["controller_gate_changed"])

    def test_mismatch_remains_mismatch(self):
        self.check(sample("MISMATCH", "MISMATCH"), "MISMATCH")

    def test_verified_remains_advisory_only(self):
        self.check(sample("VERIFIED", "VERIFIED"), "VERIFIED")

    def test_missing_report_incomplete(self):
        self.check(None, "INCOMPLETE")

    def test_nested_mismatch_fails_closed(self):
        self.check(sample("VERIFIED", "MISMATCH"), "MISMATCH")

    def test_invalid_artifact_identity(self):
        with self.assertRaises(ValueError):
            inspect_b5_advisory_report(sample("VERIFIED", "VERIFIED"), run_id=RUN,
                                       head_sha=SHA, artifact_name="wrong")


if __name__ == "__main__":
    unittest.main()
