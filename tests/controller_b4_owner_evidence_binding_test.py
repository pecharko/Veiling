"""B4 offline bound owner and OVH evidence contract regression tests."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_b4_owner_evidence_binding import evaluate

SHA = "a" * 40
ISSUE = {"number": 19, "repository_url": "https://api.github.com/repos/pecharko/Veiling",
         "user": {"login": "pecharko"}}
COMMENT = {"id": 94, "issue_url": "https://api.github.com/repos/pecharko/Veiling/issues/19",
           "user": {"login": "pecharko"}, "created_at": "2026-10-28T10:00:00Z",
           "updated_at": "2026-10-28T10:00:00Z",
           "body": "B4-BROWSER-ACCEPT demo-release " + SHA + " 99"}
OVH = {"source": "independent_ovh_read", "verified_by_trusted_reader": True,
       "run_id": 99, "release_sha": SHA, "release_id": "demo-release",
       "status": "VERIFIED"}
EVIDENCE = {"read_only": True, "review": {"independent_remote_readback": "NOT_VERIFIED"},
            "provenance": {"github_actions": {"run_id": 99, "release_sha": SHA,
                                               "status": "VERIFIED"}}}
ARGS = dict(issue=ISSUE, comment=COMMENT, expected_owner="pecharko",
            evidence=EVIDENCE, ovh_attestation=OVH, run_id=99,
            release_sha=SHA, release_id="demo-release")


class OwnerEvidenceTests(unittest.TestCase):
    def test_matching_evidence_only_yields_review(self):
        result = evaluate(**ARGS)
        self.assertEqual(result["status"], "READY_FOR_REVIEW")
        self.assertFalse(result["annotation_permitted"])
        self.assertFalse(result["writes_performed"])

    def test_untrusted_owner_issue_or_modified_comment_blocks(self):
        for change in (
            {"comment": {**COMMENT, "user": {"login": "outsider"}}},
            {"comment": {**COMMENT, "body": "groen"}},
            {"comment": {**COMMENT, "updated_at": "2026-10-28T10:05:00Z"}},
            {"issue": {**ISSUE, "repository_url": "https://api.github.com/repos/other/repo"}},
            {"issue": {**ISSUE, "pull_request": {}}},
            {"ovh_attestation": {**OVH, "verified_by_trusted_reader": False}},
            {"ovh_attestation": {**OVH, "run_id": 100}},
            {"run_id": 100},
        ):
            with self.subTest(change=change):
                result = evaluate(**{**ARGS, **change})
                self.assertNotEqual(result["status"], "READY_FOR_REVIEW")
                self.assertFalse(result["annotation_permitted"])
                self.assertFalse(result["writes_performed"])


if __name__ == "__main__":
    unittest.main()
