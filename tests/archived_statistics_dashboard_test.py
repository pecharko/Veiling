"""Regression contract: archived and live statistics must both count."""
import unittest
from pathlib import Path

PAGE = (Path(__file__).resolve().parents[1] / "catalogus-public/admin/statistieken.php").read_text()

class ArchivedStatisticsDashboardTest(unittest.TestCase):
    def test_recent_summary_uses_archived_and_live_days(self):
        self.assertIn("SELECT visitor_days visitors, views, lot_views", PAGE)
        self.assertIn("FROM visitor_stats_day_totals WHERE visit_date >= UTC_DATE() - INTERVAL 29 DAY", PAGE)
        self.assertIn("recent_days", PAGE)

    def test_daily_chart_uses_archived_totals(self):
        self.assertIn("SELECT visit_date, visitor_days visitors, views FROM visitor_stats_day_totals", PAGE)
        self.assertIn("GROUP BY visit_date ORDER BY visit_date DESC", PAGE)

    def test_top_ten_includes_archived_lot_views(self):
        self.assertIn("SELECT lot_id, views FROM visitor_stats_lot_totals", PAGE)
        self.assertIn("SELECT lot_id, views FROM visitor_stats_daily", PAGE)
        self.assertIn("page_kind='lot' AND visit_date >= UTC_DATE() - INTERVAL 29 DAY", PAGE)
        self.assertIn("GROUP BY l.id, l.lot_number, l.title ORDER BY views DESC", PAGE)

    def test_one_calendar_window_and_no_writes(self):
        chunk = PAGE.split('    // Archived days are stored in totals;')[1].split('    // Historical aggregates')[0]
        self.assertEqual(chunk.count("UNION ALL"), 3)
        self.assertEqual(chunk.count("UTC_DATE() - INTERVAL 29 DAY"), 6)
        self.assertNotIn("DELETE ", chunk)
        self.assertNotIn("INSERT ", chunk)

if __name__ == "__main__":
    unittest.main()
