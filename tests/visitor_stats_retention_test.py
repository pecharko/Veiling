#!/usr/bin/env python3
"""Offline regressions: analytics tracking must not automatically delete records."""
from pathlib import Path
import unittest

SOURCE = (Path(__file__).resolve().parents[1] / "private/visitor_stats.php").read_text()

class RetentionTests(unittest.TestCase):
    def test_tracking_does_not_cleanup_or_delete_rows(self):
        self.assertNotIn("random_int(", SOURCE)
        self.assertNotIn("DELETE FROM visitor_stats_daily", SOURCE)
        self.assertNotIn("TRUNCATE TABLE visitor_stats_daily", SOURCE)
        self.assertIn("INSERT INTO visitor_stats_daily", SOURCE)

    def test_existing_privacy_guards_still_present(self):
        self.assertIn("HTTP_DNT", SOURCE)
        self.assertIn("REMOTE_ADDR", SOURCE)
        self.assertIn("hash_hmac(", SOURCE)
        self.assertIn("HTTP_USER_AGENT", SOURCE)
        # Geo schema/lock/reader errors must also remain non-fatal for visitors.
        self.assertIn("catch (Throwable $exception)", SOURCE)
        self.assertIn("$started && $pdo->inTransaction()", SOURCE)

if __name__ == "__main__":
    unittest.main()
