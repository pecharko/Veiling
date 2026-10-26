#!/usr/bin/env python3
"""Static fail-closed contract tests for issue #224 (no production DB)."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
ARCH = (ROOT / "private/visitor_stats_archive_one_day_experimental.php").read_text()
CRON = (ROOT / "private/visitor_stats_daily_cron.php").read_text()
ADMIN = (ROOT / "catalogus-public/admin/statistieken.php").read_text()

class DailyArchiveContract(unittest.TestCase):
    def test_seven_full_days_are_retained(self):
        for text in (ARCH, CRON, ADMIN):
            self.assertIn("strtotime('-7 days')", text)
        self.assertIn("WHERE visit_date < ?", ARCH)

    def test_cli_only_and_bounded_catchup(self):
        self.assertIn("PHP_SAPI !== 'cli'", CRON)
        self.assertIn("$maxDays = 14;", CRON)
        self.assertIn("archive_catchup_limit_reached", CRON)
        self.assertIn("archive_failed days_completed=", CRON)
        self.assertIn("exit(1);", CRON)
        self.assertIn("visitor_stats_daily_cron", CRON)
        self.assertIn("GET_LOCK", CRON)
        self.assertIn("RELEASE_LOCK", CRON)
        self.assertIn("finally", CRON)
        self.assertIn("archive_visitor_stats_one_day($pdo, $cutoff)", CRON)

    def test_atomic_archive_and_no_implicit_tracking_hook(self):
        self.assertIn("beginTransaction()", ARCH)
        self.assertIn("rollBack()", ARCH)
        self.assertIn("Day exceeds safety limit (20000)", ARCH)
        self.assertLess(ARCH.index("INSERT INTO visitor_stats_day_totals"), ARCH.index("DELETE FROM visitor_stats_daily"))
        self.assertNotIn("visitor_id", CRON)
        tracking = (ROOT / "private/visitor_stats.php").read_text()
        self.assertNotIn("visitor_stats_daily_cron.php", tracking)
        # The public tracker only writes today's UTC date, not old archived days.
        self.assertIn("$day = gmdate('Y-m-d');", tracking)
        self.assertIn("$stmt->execute([$day, $identifier", tracking)
        # Unexpected historical writes must fail closed on the unique aggregate key.
        self.assertNotIn("ON DUPLICATE KEY UPDATE", ARCH)

if __name__ == "__main__":
    unittest.main()
