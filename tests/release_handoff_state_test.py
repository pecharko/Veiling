"""Offline fail-closed handoff tests; never touch GitHub or OVH."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from release_handoff_state import evaluate

SHA = "a" * 40
MAIN = "b" * 40


def facts(**changes):
    base = {"release_id": "test-release", "pr_head": SHA,
            "reviewed_manifest": True, "pr_ci": True,
            "merge_authorized": True, "merged": True,
            "main_sha": MAIN, "push_ci": True,
            "production_authorized": True, "authorization_sha": MAIN,
            "dispatch_started": True, "deployment_verified": True,
            "browser_accepted": True, "independent_evidence_verified": True}
    base.update(changes)
    return base


class HandoffTests(unittest.TestCase):
    def test_complete_only_when_all_proven(self):
        self.assertEqual(evaluate(facts())["action"], "COMPLETE")

    def test_ci_progress_does_not_require_go(self):
        self.assertEqual(evaluate(facts(push_ci=False))["action"], "WAIT_FOR_MAIN_CI")

    def test_merge_is_a_real_approval_gate(self):
        self.assertEqual(evaluate(facts(merged=False, merge_authorized=False))["action"],
                         "REQUEST_MERGE_AUTHORIZATION")

    def test_prepare_without_final_manifest(self):
        self.assertEqual(evaluate(facts(reviewed_manifest=False))["action"],
                         "PREPARE_AND_VERIFY")

    def test_production_is_distinct_approval_gate(self):
        self.assertEqual(evaluate(facts(production_authorized=False))["action"],
                         "REQUEST_PRODUCTION_AUTHORIZATION")

    def test_no_auto_dispatch_even_after_authorization(self):
        self.assertEqual(evaluate(facts(dispatch_started=False))["action"],
                         "OWNER_DISPATCH_REQUIRED")

    def test_stop_on_sha_drift(self):
        self.assertEqual(evaluate(facts(authorization_sha=SHA))["action"],
                         "STOP_AUTHORIZATION_DRIFT")

    def test_browser_acceptance_not_independent_sftp_proof(self):
        self.assertEqual(evaluate(facts(independent_evidence_verified=False))["action"],
                         "EVIDENCE_REVIEW_OPEN")

    def test_deployment_not_automatically_retried(self):
        self.assertEqual(evaluate(facts(deployment_verified=False))["action"],
                         "VERIFY_DEPLOYMENT")

    def test_malformed_inputs_fail_closed(self):
        for update in ({"release_id": "../unsafe"}, {"pr_head": "bad"},
                       {"pr_ci": "success"}, {"authorization_sha": "bad"},
                       {"untrusted_permission": True}):
            with self.subTest(update=update), self.assertRaises(ValueError):
                evaluate(facts(**update))

    def test_never_mutates(self):
        for key, value in (("merged", False), ("push_ci", False),
                           ("dispatch_started", False), ("browser_accepted", False)):
            self.assertFalse(evaluate(facts(**{key: value}))["mutates"])


if __name__ == "__main__":
    unittest.main()
