"""Offline fail-closed contract for read-only post-release reporting."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from release_b_status import summarize

SHA = "a" * 40
RUN = {"id": 123, "name": "Shared OVH manifest release with rollback",
       "event": "workflow_dispatch", "head_sha": SHA, "head_branch": "main",
       "status": "completed", "conclusion": "success"}

class ReleaseBStatusTest(unittest.TestCase):
    def artifacts(self, *names):
        return {"artifacts": [{"name": name, "expired": False} for name in names]}

    def test_success_does_not_claim_independent_acceptance(self):
        result = summarize(RUN, self.artifacts("shared-ovh-evidence-123",
                                              "shared-ovh-rollback-123"))
        self.assertEqual(result["status"], "REVIEW_ARTIFACT_CONTENTS")
        self.assertFalse(result["deployment_contents_verified"])
        self.assertEqual(result["independent_ovh_readback"], "PENDING")
        self.assertEqual(result["browser_acceptance"], "PENDING")
        self.assertEqual(result["release_administration"], "OPEN")

    def test_missing_artifact_fails_closed(self):
        result = summarize(RUN, self.artifacts("shared-ovh-evidence-123"))
        self.assertEqual(result["status"], "INCOMPLETE_ARTIFACTS")

    def test_failed_or_in_progress_is_not_success(self):
        for status, conclusion in (("in_progress", None), ("completed", "failure")):
            self.assertEqual(summarize({**RUN, "status": status, "conclusion": conclusion},
                                       self.artifacts())["status"], "RUN_FAILED_OR_INCOMPLETE")

    def test_expired_artifact_not_counted(self):
        self.assertEqual(summarize(RUN, {"artifacts": [
            {"name": "shared-ovh-evidence-123", "expired": True},
            {"name": "shared-ovh-rollback-123", "expired": False}
        ]})["status"], "INCOMPLETE_ARTIFACTS")

    def test_wrong_source_rejected(self):
        for changed in ({"head_branch": "preview/ui"}, {"event": "pull_request"},
                        {"name": "Other workflow"}, {"head_sha": "bad"}):
            with self.assertRaises(ValueError):
                summarize({**RUN, **changed}, self.artifacts())

    def test_malformed_payload_rejected(self):
        with self.assertRaises(ValueError):
            summarize(RUN, {"artifacts": "fake"})

if __name__ == "__main__":
    unittest.main()
