"""Read-only integration of existing CI feedback with release handoff."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from development_ci_feedback_adapter import format_summary

class BridgeTests(unittest.TestCase):
    def test_known_pr_gets_read_only_handoff_without_implied_approval(self):
        summary = format_summary({
            "status": "CI_GREEN_ADVISORY", "reason": "one workflow passed",
            "pr": 513, "head_sha": "a"*40,
        })
        self.assertIn("Release handoff advisory", summary)
        self.assertIn("PREPARE_AND_VERIFY", summary)
        self.assertIn("PR-only identifier", summary)
        self.assertIn("not authorized", summary)

    def test_unknown_pr_does_not_fabricate_release(self):
        summary = format_summary({"status":"HUMAN_REVIEW", "reason":"missing evidence"})
        self.assertNotIn("Release handoff advisory", summary)

    def test_bad_head_does_not_create_fake_handoff(self):
        summary = format_summary({"status":"HUMAN_REVIEW", "reason":"bad sha",
                                  "pr": 513, "head_sha": "untrusted"})
        self.assertNotIn("Release handoff advisory", summary)

if __name__ == "__main__":
    unittest.main()
