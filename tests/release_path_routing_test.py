import unittest

from scripts.release_path_routing import classify


class ReleasePathRoutingTests(unittest.TestCase):
    def test_shared_only(self):
        self.assertEqual(
            classify(["catalogus-public/admin/statistieken.php", "private/visitor_stats_cron_log.php"])["shared_ovh"],
            ["catalogus-public/admin/statistieken.php", "private/visitor_stats_cron_log.php"],
        )

    def test_mixed_cron_split_without_gate_change(self):
        result = classify([
            "catalogus-public/admin/statistieken.php",
            "private/visitor_stats_cron_log.php",
            "private/visitor_stats_daily_cron.php",
        ])
        self.assertEqual(result["special"]["issue224-cron-onefile"], ["private/visitor_stats_daily_cron.php"])
        self.assertTrue(result["requires_separate_release"])
        self.assertNotIn("private/visitor_stats_daily_cron.php", result["shared_ovh"])

    def test_special_only(self):
        result = classify(["private/visitor_stats_daily_cron.php"])
        self.assertEqual(result["shared_ovh"], [])
        self.assertFalse(result["requires_separate_release"])

    def test_unknown_protected_fail_closed(self):
        for path in ("private/config.php", "private/snapshots.php", ".github/workflows/shared-ovh-release.yml", "../x.php"):
            with self.subTest(path=path), self.assertRaises(ValueError):
                classify([path])

    def test_duplicates_and_empty_fail_closed(self):
        for paths in ([], ["private/visitor_stats_daily_cron.php"] * 2, [""], ["private/foo.php "], [123]):
            with self.subTest(paths=paths), self.assertRaises(ValueError):
                classify(paths)


if __name__ == "__main__":
    unittest.main()
