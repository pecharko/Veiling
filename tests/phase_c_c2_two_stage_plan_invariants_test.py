"""Offline invariant tests for the two-stage nonce-then-CAS GraphQL plan.

No API calls, no nonce creation, no writer authority.
"""
import unittest
from copy import deepcopy

from scripts.phase_c_atomic_refs_plan import ZERO_SHA, propose_atomic_update_refs
from scripts.phase_c_c2_attempt_state import Attempt, transition


class TwoStageInvariantTests(unittest.TestCase):
    def test_state_model_never_authorizes_real_graphql(self):
        state = Attempt()
        for event in ("reserve_confirmed", "submit_ref_update", "response_ambiguous"):
            result = transition(state, event)
            self.assertFalse(result["write_allowed"])
            self.assertFalse(result["retry_allowed"])
            state = result["attempt"]
        self.assertEqual(state.state, "AMBIGUOUS")
        for event in ("submit_ref_update", "reserve_confirmed", "verified_exact"):
            self.assertNotIn("attempt", transition(state, event))

    def test_nonce_must_be_zero_before_creation_and_not_force(self):
        # A strict zero-before nonce and strict head-before branch CAS are
        # independently required, regardless of simulated approval evidence.
        import inspect
        source = inspect.getsource(propose_atomic_update_refs)
        self.assertIn('"beforeOid": ZERO_SHA', source)
        self.assertIn('"beforeOid": head', source)
        self.assertIn('"force": False', source)
        self.assertIn('"reservation_before_branch": True', source)
        self.assertIn('"never_retry_same_issue": True', source)
        self.assertIn('"never_delete_nonce": True', source)

    def test_planner_does_not_trust_incomplete_evidence(self):
        for evidence in (None, [], {}, {"owner_verified": True},
                         {"repo": "pecharko/Veiling", "owner_verified": True}):
            result = propose_atomic_update_refs(evidence)
            self.assertFalse(result["write_allowed"])
            self.assertFalse(result["merge_allowed"])
            self.assertFalse(result["dispatch_allowed"])
            self.assertFalse(result["production_authorized"])

    def test_rejected_nonce_does_not_enable_branch_step(self):
        first = transition(Attempt(), "reserve_failed")["attempt"]
        self.assertEqual(first.state, "FAILED_CLOSED")
        self.assertTrue(first.nonce_spent)
        self.assertNotIn("attempt", transition(first, "submit_ref_update"))


if __name__ == "__main__":
    unittest.main()
