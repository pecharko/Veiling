"""B4 annotation-plan replay and fail-closed tests; no network/writes."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_b4_registration_plan import plan

SHA = "a" * 40
D = {"status": "READY_FOR_REVIEW", "annotation_permitted": False,
     "writes_performed": False,
     "identity": {"run_id": 12, "release_sha": SHA, "release_id": "release-test"},
     "owner_comment_id": 50, "owner_issue": 44}


class B4RegistrationPlanTests(unittest.TestCase):
    def test_plan_remains_inert(self):
        x = plan(D, issue_number=44, existing_comments=[])
        self.assertEqual(x["status"], "READY_FOR_MANUAL_REVIEW")
        self.assertFalse(x["write_permitted"])
        self.assertFalse(x["write_executed"])
        self.assertIn("veiling-b4-accepted:release-test", x["proposed_body"])

    def test_duplicate_prevents_replay(self):
        body = plan(D, issue_number=44, existing_comments=[])["proposed_body"]
        x = plan(D, issue_number=44, existing_comments=[{"id": 1, "body": body}])
        self.assertEqual(x["status"], "ALREADY_RECORDED")
        self.assertIsNone(x["proposed_body"])

    def test_conflict_or_incomplete_history_blocks(self):
        for comments in ([{"id": 1, "body": "<!-- veiling-b4-accepted:other -->"}],
                         [{"id": "bad", "body": "ok"}],
                         [{"id": i, "body": ""} for i in range(100)]):
            self.assertEqual(plan(D, issue_number=44, existing_comments=comments)["status"],
                             "BLOCKED")

    def test_wrong_target_or_unverified_acceptance_blocks(self):
        for kwargs in ({"issue_number": 45}, {"decision": {**D, "status": "PENDING"}},
                       {"decision": {**D, "annotation_permitted": True}}):
            args = {"decision": D, "issue_number": 44, "existing_comments": []}
            args.update(kwargs)
            self.assertEqual(plan(**args)["status"], "BLOCKED")


if __name__ == "__main__":
    unittest.main()
