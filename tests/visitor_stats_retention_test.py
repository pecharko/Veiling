#!/usr/bin/env python3
"""Offline regression checks: visitor analytics retention never random."""
from pathlib import Path
import unittest

SOURCE = (Path(__file__).resolve().parents[1] / "private/visitor_stats.php").read_text()

class RetentionTests(unittest.TestCase):
    def test_cleanup_on_each_successful_tracking_request(self):
        self.assertNotIn("random_int(", SOURCE)
        self.assertIn("DELETE FROM visitor_stats_daily WHERE visit_date < UTC_DATE() - INTERVAL 29 DAY", SOURCE)
        self.assertLess(SOURCE.index("DELETE FROM visitor_stats_daily"), SOURCE.index("INSERT INTO visitor_stats_daily"))

    def test_existing_privacy_guards_still_present(self):
        self.assertIn("HTTP_DNT", SOURCE)
        self.assertIn("REMOTE_ADDR", SOURCE)
        self.assertIn("hash_hmac(", SOURCE)
        self.assertIn("HTTP_USER_AGENT", SOURCE)
        self.assertIn("PDOException", SOURCE)

if __name__ == "__main__":
    unittest.main()
