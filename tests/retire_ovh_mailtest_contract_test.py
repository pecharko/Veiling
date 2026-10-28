"""Offline checks only: never connects to OVH."""
import unittest
from scripts.retire_ovh_mailtest import validate_authorization, TARGET, EXPECTED_SHA, RELEASE_ID


class RetirementContractTest(unittest.TestCase):
    def test_scope_is_fixed(self):
        self.assertEqual(TARGET, "catalogus-public/admin/mailtest-eenmalig.php")
        self.assertEqual(len(EXPECTED_SHA), 64)

    def test_plan_requires_no_write_authorization(self):
        validate_authorization("plan", "", "")

    def test_delete_requires_exact_independent_approval(self):
        sha = "a" * 40
        with self.assertRaises(ValueError):
            validate_authorization("delete", sha, "")
        with self.assertRaises(ValueError):
            validate_authorization("delete", "short", "DELETE-" + RELEASE_ID + "-short")
        validate_authorization("delete", sha, "DELETE-" + RELEASE_ID + "-" + sha)

    def test_other_modes_rejected(self):
        with self.assertRaises(ValueError):
            validate_authorization("upload", "", "")


if __name__ == "__main__":
    unittest.main()
