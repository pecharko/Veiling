#!/usr/bin/env python3
"""Offline fail-closed static regression checks; no production connection."""
from pathlib import Path
import re
import unittest

SQL = Path("database/visitor_stats_install_proposed.sql").read_text()

class StatisticsSchemaContract(unittest.TestCase):
    def test_exact_tables(self):
        names = re.findall(r"^CREATE TABLE ([a-z_]+) \\(", SQL, re.M)
        self.assertEqual(names, ["visitor_stats_daily", "visitor_stats_day_totals", "visitor_stats_lot_totals"])
    def test_no_destructive_or_silent_migration(self):
        statements = [s.strip() for s in SQL.split(";") if s.strip()]
        self.assertEqual(len(statements), 3)
        for forbidden in ["IF NOT EXISTS", "DROP TABLE", "ALTER TABLE", "DELETE FROM",
                          "TRUNCATE", "UPDATE ", "INSERT INTO", "CREATE USER"]:
            self.assertNotIn(forbidden, SQL.upper())
        self.assertEqual(SQL.count("ENGINE=InnoDB"), 3)
    def test_source_primary_index_and_ids(self):
        self.assertIn("PRIMARY KEY (visit_date, visitor_id, page_kind, lot_id)", SQL)
        self.assertIn("visitor_id BINARY(32) NOT NULL", SQL)
        self.assertIn("page_kind ENUM('home','lot') NOT NULL", SQL)
        self.assertIn("lot_id BIGINT UNSIGNED NOT NULL DEFAULT 0", SQL)
        self.assertIn("views BIGINT UNSIGNED NOT NULL DEFAULT 1", SQL)
    def test_aggregate_uniqueness(self):
        self.assertIn("PRIMARY KEY (visit_date)", SQL)
        self.assertIn("PRIMARY KEY (visit_date, lot_id)", SQL)

if __name__ == "__main__":
    unittest.main()
