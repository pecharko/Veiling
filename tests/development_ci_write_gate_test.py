import unittest
from scripts.development_ci_write_gate import assess_repair_write


def request():
    return {
        "pr": 42, "expected_head_sha": "a" * 40,
        "actual_head_sha": "a" * 40, "branch": "test/phase-c-sandbox",
        "path": "tests/sandbox_test.php", "base_blob_sha": "b" * 40,
        "replacement": "<?php\nreturn false;\n", "attempts": 0,
        "author_approved": True,
    }


class OfflineWriteGateTest(unittest.TestCase):
    def test_all_valid_is_still_not_a_write_authorization(self):
        result = assess_repair_write(request())
        self.assertEqual(result["decision"], "ELIGIBLE_FOR_SEPARATE_WRITE_APPROVAL")
        self.assertFalse(result["can_write"])
        self.assertFalse(result["can_merge"])
        self.assertFalse(result["can_dispatch"])
        self.assertFalse(result["production_authorized"])

    def test_reject_all_sensitive_paths(self):
        for path in (
            ".github/workflows/verified-deploy-controller.yml",
            "private/visitor_stats.php", "database/migrations/x.sql",
            "scripts/development_ci_write_gate.py",
            "catalogus-public/admin/statistieken.php", "releases/a.json",
            "release-requests/a.json", "tests/../sandbox_test.php",
        ):
            with self.subTest(path=path):
                data = request()
                data["path"] = path
                self.assertEqual(assess_repair_write(data)["decision"], "DENY")

    def test_head_drift_and_other_gates(self):
        for key, value in (
            ("actual_head_sha", "b" * 40), ("branch", "main"),
            ("branch", "feature/geo-test"), ("base_blob_sha", ""),
            ("attempts", 1), ("attempts", False),
            ("author_approved", False), ("replacement", "not-php"),
            ("replacement", "<?php" + "x" * 3000),
            ("pr", True),
        ):
            with self.subTest(key=key):
                data = request()
                data[key] = value
                self.assertEqual(assess_repair_write(data)["decision"], "DENY")


if __name__ == "__main__":
    unittest.main()
