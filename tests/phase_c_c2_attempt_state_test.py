import unittest
from dataclasses import replace

from scripts.phase_c_c2_attempt_state import Attempt, transition, EVENTS


class C2AttemptStateTests(unittest.TestCase):
    def advance(self, attempt, event):
        r = transition(attempt, event)
        self.assertFalse(r["write_allowed"])
        self.assertFalse(r["retry_allowed"])
        return r.get("attempt")

    def test_happy_path_still_never_authorizes_writer(self):
        a = self.advance(Attempt(), "reserve_confirmed")
        self.assertEqual((a.state, a.nonce_spent), ("NONCE_RESERVED", True))
        a = self.advance(a, "submit_ref_update")
        self.assertEqual(a.update_attempts, 1)
        a = self.advance(a, "verified_exact")
        self.assertEqual(a.state, "VERIFIED")
        for event in EVENTS:
            self.assertIsNone(self.advance(a, event))

    def test_ambiguous_response_consumes_nonce_and_blocks_replay(self):
        a = self.advance(Attempt(), "reserve_confirmed")
        a = self.advance(a, "submit_ref_update")
        a = self.advance(a, "response_ambiguous")
        self.assertEqual(a.state, "AMBIGUOUS")
        self.assertTrue(a.nonce_spent)
        for event in EVENTS:
            self.assertIsNone(self.advance(a, event))

    def test_reservation_failure_is_terminal(self):
        a = self.advance(Attempt(), "reserve_failed")
        self.assertEqual(a.state, "FAILED_CLOSED")
        self.assertIsNone(self.advance(a, "reserve_confirmed"))

    def test_unreserved_or_second_attempt_is_denied(self):
        self.assertIsNone(self.advance(Attempt(), "submit_ref_update"))
        a = self.advance(Attempt(), "reserve_confirmed")
        self.assertIsNone(self.advance(a, "reserve_confirmed"))
        a = self.advance(a, "submit_ref_update")
        self.assertIsNone(self.advance(a, "submit_ref_update"))

    def test_forged_state_and_bad_events(self):
        for a in (None, {}, replace(Attempt(), state="VERIFIED"),
                  replace(Attempt(), state="NONCE_RESERVED", nonce_spent=False)):
            self.assertEqual(transition(a, "submit_ref_update")["decision"], "DENY")
        self.assertEqual(transition(Attempt(), None)["decision"], "DENY")


if __name__ == "__main__":
    unittest.main()
