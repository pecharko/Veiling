#!/usr/bin/env python3
"""Offline safety regressions for admin manual visitor statistics deletion."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / "catalogus-public/admin/statistieken.php").read_text()
TRACKING = (ROOT / "private/visitor_stats.php").read_text()

class AnalyticsAdminDeletionTests(unittest.TestCase):
    def test_admin_auth_and_csrf_guard_before_writes(self):
        self.assertLess(SOURCE.index("require_auth();"), SOURCE.index("if (is_post())"))
        self.assertLess(SOURCE.index("check_csrf();"), SOURCE.index("DELETE FROM visitor_stats_daily"))
        self.assertIn("csrf_field()", SOURCE)

    def test_only_explicit_two_step_post_can_delete(self):
        self.assertIn("preview_delete", SOURCE)
        self.assertIn("confirm_delete", SOURCE)
        self.assertIn("hash_equals(", SOURCE)
        self.assertIn("random_bytes(24)", SOURCE)
        self.assertIn("'expires' => time() + 300", SOURCE)
        self.assertIn("unset($_SESSION['analytics_delete_pending'])", SOURCE)
        self.assertIn("$phrase !== 'VERWIJDER'", SOURCE)
        self.assertNotIn("TRUNCATE", SOURCE.replace("No TRUNCATE", ""))

    def test_deletes_only_analytics_and_uses_bound_date(self):
        self.assertIn("DELETE FROM visitor_stats_daily WHERE visit_date < ?", SOURCE)
        self.assertIn("$stmt->execute([$cutoff]);", SOURCE)
        self.assertIn("DELETE FROM visitor_stats_daily')", SOURCE)
        self.assertIn("DateTimeImmutable::createFromFormat('!Y-m-d'", SOURCE)
        self.assertIn("gmdate('Y-m-d')", SOURCE)
        self.assertNotIn("DELETE FROM visitor_stats_daily", TRACKING)

    def test_admin_displays_cutoff_exclusive_and_full_deletion(self):
        self.assertIn("De gekozen datum zelf blijft behouden", SOURCE)
        self.assertIn("Verwijder alle statistieken", SOURCE)
        self.assertIn('type="date"', SOURCE)
        self.assertIn("Het aantal kan bij gelijktijdige bezoekers", SOURCE)

if __name__ == "__main__":
    unittest.main()
