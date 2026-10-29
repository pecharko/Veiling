"""Offline proof that reconciliation evidence never reopens a spent attempt."""
import unittest
from dataclasses import replace

from scripts.phase_c_c2_attempt_state import Attempt, transition


class C2ReconciliationInvariantTests(unittest.TestCase):
    def test_all_terminal_states_reject_all_events(self):
        terminal_states = ("VERIFIED", "AMBIGUOUS", "FAILED_CLOSED")
        events = ("reserve_confirmed", "reserve_failed", "submit_ref_update",
                  "verified_exact", "response_ambiguous", "update_rejected")
        for state in terminal_states:
            attempt = Attempt(state=state, nonce_spent=True, update_attempts=1)
            for event in events:
                with self.subTest(state=state, event=event):
                    result = transition(attempt, event)
                    self.assertEqual(result["decision"], "DENY")
                    self.assertFalse(result["write_allowed"])
                    self.assertFalse(result["retry_allowed"])
                    self.assertNotIn("attempt", result)

    def test_ambiguous_reservation_cannot_be_assumed_successful(self):
        reserved = transition(Attempt(), "reserve_confirmed")["attempt"]
        attempted = transition(reserved, "submit_ref_update")["attempt"]
        ambiguous = transition(attempted, "response_ambiguous")["attempt"]
        self.assertEqual(ambiguous.state, "AMBIGUOUS")
        self.assertTrue(ambiguous.nonce_spent)
        self.assertEqual(ambiguous.update_attempts, 1)
        # Read-only reconciliation is advisory; it cannot issue another attempt.
        for event in ("submit_ref_update", "reserve_confirmed", "verified_exact"):
            self.assertNotIn("attempt", transition(ambiguous, event))

    def test_forged_inconsistent_states_fail_closed(self):
        forged = (
            Attempt(state="NONCE_RESERVED", nonce_spent=False),
            Attempt(state="NONCE_RESERVED", nonce_spent=True, update_attempts=1),
            Attempt(state="REF_UPDATE_ATTEMPTED", nonce_spent=False, update_attempts=1),
            Attempt(state="REF_UPDATE_ATTEMPTED", nonce_spent=True, update_attempts=2),
        )
        for attempt in forged:
            for event in ("submit_ref_update", "verified_exact", "response_ambiguous"):
                with self.subTest(attempt=attempt, event=event):
                    result = transition(attempt, event)
                    self.assertNotIn("attempt", result)
                    self.assertFalse(result["retry_allowed"])


if __name__ == "__main__":
    unittest.main()
