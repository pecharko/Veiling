#!/usr/bin/env python3
"""Safety gates for explicitly triggered, transaction-protected archival."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
ARCH = (ROOT / "private/visitor_stats_archive.php").read_text()
ADMIN = (ROOT / "catalogus-public/admin/statistieken.php").read_text()
TRACK = (ROOT / "private/visitor_stats.php").read_text()
SQL = (ROOT / "database/visitor_stats_aggregates.sql").read_text()

class ManualArchiveTests(unittest.TestCase):
    def test_explicit_only_and_dated(self):
        self.assertNotIn("visitor_stats_archive.php", TRACK)
        self.assertIn("preview_archive", ADMIN)
        self.assertIn("confirm_archive", ADMIN)
        self.assertIn("ARCHIVEER", ADMIN)
        self.assertIn("check_csrf();", ADMIN)
        self.assertIn("hash_equals(", ADMIN)
        self.assertIn("analytics_archive_pending", ADMIN)
        self.assertIn("strtotime('-7 days')", ADMIN)
        self.assertIn("strtotime('-30 days')", ARCH)
        self.assertIn("FOR UPDATE", ARCH)

    def test_transaction_and_row_verification(self):
        for marker in ("beginTransaction()", "rollBack()", "commit()", "rowCount()", "Archive checksum mismatch"):
            self.assertIn(marker, ARCH)
        self.assertLess(ARCH.index("INSERT INTO visitor_stats_day_totals"), ARCH.index("DELETE FROM visitor_stats_daily"))
        self.assertIn("INSERT INTO visitor_stats_lot_totals", ARCH)
        self.assertNotIn("ON DUPLICATE KEY UPDATE", ARCH)
        self.assertIn("DELETE FROM visitor_stats_daily WHERE visit_date < ?", ARCH)
        self.assertIn("SELECT visit_date, visitor_id, page_kind", ARCH)

    def test_admin_deletes_both_aggregate_tables(self):
        for table in ("visitor_stats_day_totals", "visitor_stats_lot_totals", "visitor_stats_daily"):
            self.assertIn("DELETE FROM " + table, ADMIN)
        self.assertIn("$pdo->beginTransaction();", ADMIN)
        self.assertIn("$pdo->commit();", ADMIN)
        self.assertIn("$pdo->rollBack();", ADMIN)
        self.assertIn("Volledige veilingcampagne", ADMIN)

    def test_new_schema_no_identifiers(self):
        self.assertIn("CREATE TABLE IF NOT EXISTS visitor_stats_day_totals", SQL)
        self.assertIn("CREATE TABLE IF NOT EXISTS visitor_stats_lot_totals", SQL)
        for secret_field in ("visitor_id", "user_agent", "ip_address"):
            self.assertNotIn(secret_field + " BINARY", SQL)
        self.assertIn("PRIMARY KEY (visit_date, lot_id)", SQL)

if __name__ == "__main__":
    unittest.main()
