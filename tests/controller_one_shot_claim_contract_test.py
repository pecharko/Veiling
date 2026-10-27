"""B3.2 claim policy regression tests without IO, persistence, dispatch or networking."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_one_shot_claim_contract import claim_key, review_claim

K = claim_key("pecharko/Veiling", 512, 912, "shared-demo", "a" * 40)


class OneShotTests(unittest.TestCase):
    def test_reject_wrong_identity(self):
        for args in [
            ("other/Veiling", 512, 912, "shared-demo", "a" * 40),
            ("pecharko/Veiling", 0, 912, "shared-demo", "a" * 40),
            ("pecharko/Veiling", 512, True, "shared-demo", "a" * 40),
            ("pecharko/Veiling", 512, 912, "shared-demo", "short"),
        ]:
            with self.subTest(args=args), self.assertRaises(ValueError):
                claim_key(*args)

    def test_no_durable_cas_never_allows_dispatch(self):
        for proof in (False, None, 0, "true"):
            x = review_claim(K, None, durable_cas_proven=proof)
            self.assertFalse(x["dispatch_allowed"])

    def test_all_states_including_new_and_unknown_are_blocked(self):
        for state in ("UNCLAIMED", "CLAIMED", "DISPATCH_UNKNOWN",
                      "DISPATCH_CONFIRMED", "CLOSED"):
            record = {"key": K, "state": state,
                      "run_id": 123 if state == "DISPATCH_CONFIRMED" else None}
            x = review_claim(K, record, durable_cas_proven=True)
            self.assertFalse(x["dispatch_allowed"])
            self.assertFalse(x["automatic_retry_allowed"])

    def test_replay_mismatched_comment_and_release_denied(self):
        alternate = claim_key("pecharko/Veiling", 512, 913,
                              "shared-demo", "a" * 40)
        x = review_claim(K, {"key": alternate, "state": "CLAIMED",
                             "run_id": None}, durable_cas_proven=True)
        self.assertFalse(x["dispatch_allowed"])

    def test_uncertain_dispatch_never_retried(self):
        x = review_claim(K, {"key": K, "state": "DISPATCH_UNKNOWN",
                             "run_id": None}, durable_cas_proven=True)
        self.assertEqual(x["claim_state"], "DISPATCH_UNKNOWN")
        self.assertFalse(x["automatic_retry_allowed"])

    def test_unverified_run_provenance_denied(self):
        x = review_claim(K, {"key": K, "state": "DISPATCH_CONFIRMED",
                             "run_id": None}, durable_cas_proven=True)
        self.assertEqual(x["reason"], "invalid_run_provenance")


if __name__ == "__main__":
    unittest.main()
