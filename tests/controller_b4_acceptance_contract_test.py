"""Offline B4 security contract: no automatic acceptance or GitHub writes."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_b4_acceptance_contract import decide

SHA = "a" * 40
EVIDENCE = {
    "read_only": True,
    "provenance": {"github_actions": {"run_id": 12, "release_sha": SHA,
                                      "status": "VERIFIED"}},
    "review": {"independent_remote_readback": "VERIFIED"},
}
OWNER = {"verified_owner": True, "confirmed_browser": True, "run_id": 12,
         "release_sha": SHA, "release_id": "sample-release", "comment_id": 31,
         "issue": 44}
ARGS = {"evidence": EVIDENCE, "run_id": 12, "sha": SHA,
        "release_id": "sample-release", "owner_acceptance": OWNER}


class B4AcceptanceContractTests(unittest.TestCase):
    def test_verified_separate_owner_action_only_prepares_review(self):
        r = decide(**ARGS)
        self.assertEqual(r["status"], "READY_FOR_REVIEW")
        self.assertFalse(r["annotation_permitted"])
        self.assertFalse(r["writes_performed"])
        self.assertEqual(r["owner_comment_id"], 31)

    def test_green_run_never_implies_browser_acceptance(self):
        r = decide(**{**ARGS, "owner_acceptance": None})
        self.assertEqual(r["reason"], "owner_browser_acceptance_pending")
        self.assertEqual(r["browser_acceptance"], "PENDING")

    def test_runner_readback_is_not_independent_ovh(self):
        r = decide(**{**ARGS, "evidence": {
            **EVIDENCE, "review": {"independent_remote_readback": "NOT_VERIFIED"}}})
        self.assertEqual(r["reason"], "independent_ovh_readback_pending")

    def test_untrusted_identity_owner_or_comment_is_blocked(self):
        cases = [
            {"sha": "b" * 40},
            {"run_id": 13},
            {"release_id": "other-release"},
            {"owner_acceptance": {**OWNER, "verified_owner": False}},
            {"owner_acceptance": {**OWNER, "comment_id": True}},
            {"owner_acceptance": {**OWNER, "confirmed_browser": False}},
            {"evidence": {**EVIDENCE, "provenance": {
                "github_actions": {"run_id": 12, "release_sha": SHA, "status": "FAILED"}}}},
        ]
        for change in cases:
            with self.subTest(change=change):
                r = decide(**{**ARGS, **change})
                self.assertNotEqual(r["status"], "READY_FOR_REVIEW")
                self.assertFalse(r["annotation_permitted"])
                self.assertFalse(r["writes_performed"])


if __name__ == "__main__":
    unittest.main()
