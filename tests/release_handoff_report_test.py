"""Offline formatting tests for non-executing release handoff report."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from release_handoff_report import summary

class ReportTests(unittest.TestCase):
    def test_no_go_after_merged_ci_pending(self):
        text = summary({"release_id": "example-release", "pr_head": "a"*40,
                        "main_sha":"b"*40, "reviewed_manifest": True,
                        "pr_ci": True, "merged": True, "push_ci": False})
        self.assertIn("WAIT_FOR_MAIN_CI", text)
        self.assertIn("Do not request", text)
        self.assertIn("not authorized", text)

    def test_exact_owner_dispatch_boundary(self):
        data = {"release_id": "example-release", "pr_head": "a"*40,
                "main_sha":"b"*40, "reviewed_manifest": True,
                "pr_ci": True, "merged": True, "push_ci": True,
                "production_authorized": True, "authorization_sha": "b"*40}
        self.assertIn("OWNER_DISPATCH_REQUIRED", summary(data))
        self.assertIn("not authorized", summary(data))

    def test_evidence_does_not_disappear_after_browser_acceptance(self):
        data = {"release_id": "example-release", "pr_head": "a"*40,
                "main_sha":"b"*40, "reviewed_manifest": True,
                "pr_ci": True, "merged": True, "push_ci": True,
                "production_authorized": True, "authorization_sha": "b"*40,
                "dispatch_started": True, "deployment_verified": True,
                "browser_accepted": True}
        self.assertIn("EVIDENCE_REVIEW_OPEN", summary(data))

    def test_unknown_data_fail_closed(self):
        with self.assertRaises(ValueError):
            summary({"release_id": "bad", "pr_head": "bad", "merged": True})

if __name__ == "__main__":
    unittest.main()
