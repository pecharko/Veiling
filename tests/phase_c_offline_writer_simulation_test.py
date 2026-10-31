"""Adversarial tests: no real GitHub write operations."""
import unittest
from dataclasses import replace
from scripts.phase_c_offline_writer_simulation import Fixture, simulate_once


class OfflineWriterSimulationTest(unittest.TestCase):
    def test_simulated_happy_path_is_not_authorization(self):
        result = simulate_once(Fixture())
        self.assertEqual(result["state"], "SIMULATED_SUCCESS_NOT_AUTHORIZATION")
        self.assertEqual(result["operations"], [
            "SIMULATED_CREATE_NONCE", "SIMULATED_NON_FORCE_CAS", "SIMULATED_READBACK"])
        self.assertFalse(result["write_allowed"])
        self.assertFalse(result["retry_allowed"])

    def test_preconditions_fail_before_reservation(self):
        examples = [
            {"branch": "main"}, {"head": "a" * 40}, {"blob": "b" * 40},
            {"issue": 426}, {"path": "catalogus-public/index.php"},
            {"owner_verified": False}, {"rules_verified": False},
            {"attempts": 1}, {"old_content": "<?php\nreturn false;\n"},
            {"old_content": "<?php\nreturn flase;\nreturn flase;\n"},
        ]
        for change in examples:
            with self.subTest(change=change):
                result = simulate_once(replace(Fixture(), **change))
                self.assertEqual(result["state"], "DENY")
                self.assertEqual(result["operations"], [])
                self.assertFalse(result["retry_allowed"])

    def test_duplicate_nonce_does_not_touch_branch(self):
        result = simulate_once(replace(Fixture(), nonce_exists=True))
        self.assertEqual(result["operations"], [])
        self.assertEqual(result["state"], "DENY")

    def test_uncertain_reservation_is_terminal(self):
        for status in ("timeout", "conflict", "rejected", "unknown"):
            with self.subTest(status=status):
                result = simulate_once(replace(Fixture(), nonce_create_result=status))
                self.assertEqual(result["operations"], ["SIMULATED_CREATE_NONCE"])
                self.assertFalse(result["retry_allowed"])

    def test_cas_failure_never_retries(self):
        for status in ("conflict", "timeout", "unknown"):
            with self.subTest(status=status):
                result = simulate_once(replace(Fixture(), cas_result=status))
                self.assertEqual(result["operations"], [
                    "SIMULATED_CREATE_NONCE", "SIMULATED_NON_FORCE_CAS"])
                self.assertFalse(result["retry_allowed"])

    def test_readback_mismatch_is_terminal(self):
        result = simulate_once(replace(Fixture(), readback_matches=False))
        self.assertEqual(result["state"], "DENY")
        self.assertFalse(result["retry_allowed"])


if __name__ == "__main__":
    unittest.main()
