"""Offline B3.2 adversarial transaction model tests: no storage or dispatch."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_one_shot_claim_contract import claim_key
from controller_transactional_claim_model import simulate_atomic_claim

def key(issue=512, comment=912, release="shared-demo", sha="a"*40):
    return claim_key("pecharko/Veiling",issue,comment,release,sha)

class TransactionalClaimTests(unittest.TestCase):
    def test_first_model_claim_never_grants_dispatch(self):
        ledger, result = simulate_atomic_claim((), key())
        self.assertEqual(len(ledger), 1)
        self.assertFalse(result["dispatch_allowed"])

    def test_exact_redelivery_and_second_comment_cannot_reclaim_release(self):
        ledger, _ = simulate_atomic_claim((), key())
        for attempted in (key(), key(comment=913), key(issue=513,comment=914)):
            after, outcome = simulate_atomic_claim(ledger, attempted)
            self.assertEqual(after, ledger)
            self.assertEqual(outcome["status"], "UNIQUE_CONFLICT")
            self.assertFalse(outcome["dispatch_allowed"])

    def test_same_comment_cannot_claim_different_release(self):
        ledger, _ = simulate_atomic_claim((), key())
        after, outcome = simulate_atomic_claim(ledger, key(release="different-release"))
        self.assertEqual(after, ledger)
        self.assertEqual(outcome["status"], "UNIQUE_CONFLICT")

    def test_unknown_commit_never_retried_or_dispatches(self):
        for outcome in ("unknown", "rejected"):
            ledger, result = simulate_atomic_claim((), key(), result=outcome)
            self.assertFalse(ledger)
            self.assertFalse(result["automatic_retry_allowed"])
            self.assertFalse(result["dispatch_allowed"])

    def test_two_contenders_sequential_atomic_model_accepts_at_most_one(self):
        ledger = ()
        winners = 0
        for _ in range(2):
            ledger, result = simulate_atomic_claim(ledger, key())
            winners += result["status"] == "MODEL_COMMIT_NOT_AUTHORITY"
            self.assertFalse(result["dispatch_allowed"])
        self.assertEqual(winners, 1)
        self.assertEqual(len(ledger), 1)

    def test_invalid_input_fails_closed(self):
        with self.assertRaises(ValueError):
            simulate_atomic_claim([], key())
        with self.assertRaises(ValueError):
            simulate_atomic_claim((), key(), result="timeout_retry")

if __name__ == "__main__":
    unittest.main()
