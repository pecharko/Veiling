import unittest
from copy import deepcopy
from scripts.phase_c_app_receipt import inspect_receipt, APP_ID, INSTALLATION_ID


class AppReceiptTests(unittest.TestCase):
    def fixture(self):
        return ({"id": INSTALLATION_ID, "app_id": APP_ID,
                 "account": {"login": "pecharko"},
                 "repository_selection": "selected",
                 "permissions": {"contents": "read", "metadata": "read"}},
                [{"id": 42, "full_name": "pecharko/Veiling"}])

    def test_expected_receipt_still_cannot_authorize(self):
        result = inspect_receipt(*self.fixture())
        self.assertTrue(result["read_only_receipt_consistent"])
        self.assertEqual(result["decision"], "DENY")
        self.assertFalse(result["write_allowed"])

    def test_wrong_or_escalated_installation_fails_closed(self):
        for field, value in [("id", True), ("id", 172331568),
                             ("app_id", 1), ("account", {"login": "someone"}),
                             ("repository_selection", "all"),
                             ("permissions", {"contents": "write", "metadata": "read"}),
                             ("permissions", {"contents": "read", "metadata": "read", "issues": "write"})]:
            with self.subTest(field=field, value=value):
                i, r = self.fixture()
                i[field] = value
                self.assertNotIn("read_only_receipt_consistent", inspect_receipt(i, r))

    def test_extra_or_different_repositories_fail(self):
        i, r = self.fixture()
        self.assertNotIn("read_only_receipt_consistent", inspect_receipt(i, r + deepcopy(r)))
        r[0]["full_name"] = "pecharko/Other"
        self.assertNotIn("read_only_receipt_consistent", inspect_receipt(i, r))

    def test_malformed(self):
        for v in (None, "", [], 0):
            self.assertEqual(inspect_receipt(v, [])["decision"], "DENY")


if __name__ == "__main__":
    unittest.main()
