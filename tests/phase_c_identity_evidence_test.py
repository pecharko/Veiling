import unittest
from dataclasses import replace
from scripts.phase_c_identity_evidence import IdentityEvidence, inspect_identity, HEAD, ISSUE, REPO


class ActorSeparationTests(unittest.TestCase):
    def valid(self):
        return IdentityEvidence(REPO, HEAD, ISSUE, "pecharko", "pecharko",
                                "pecharko", "github-actions[bot]", "sandbox-writer[bot]",
                                "github_app", True, True, True)

    def test_positive_claims_still_deny(self):
        result = inspect_identity(self.valid())
        self.assertTrue(result["evidence_consistent"])
        self.assertFalse(result["write_allowed"])

    def test_forged_or_shared_actor_denied(self):
        variations = (
            {"writer_actor": "github-actions[bot]"}, {"writer_actor": "pecharko"},
            {"issue_author": "another"}, {"comment_author": "another"},
            {"writer_kind": "personal_token"}, {"writer_actor": ""},
            {"token_permission_verified": False}, {"tag_rule_verified": None},
            {"branch_rule_verified": 1}, {"pr_head": "0" * 40},
            {"repository": "other/Veiling"}, {"issue_number": True},
        )
        for variant in variations:
            with self.subTest(variant=variant):
                result = inspect_identity(replace(self.valid(), **variant))
                self.assertEqual(result["decision"], "DENY")
                self.assertNotIn("evidence_consistent", result)

    def test_malformed_payloads(self):
        for value in (None, {}, True, "approved", object()):
            self.assertFalse(inspect_identity(value)["write_allowed"])


if __name__ == "__main__":
    unittest.main()
