#!/usr/bin/env python3
"""Offline static regression: install only the two aggregate tables."""
from pathlib import Path
import re
import unittest

SQL = Path("database/visitor_stats_install_proposed.sql").read_text()
DDL = "\n".join(line for line in SQL.splitlines() if not line.lstrip().startswith("--"))

class StatisticsSchemaContract(unittest.TestCase):
    def test_exactly_two_aggregates_and_no_daily_modification(self):
        names = [line.split()[2] for line in DDL.splitlines() if line.startswith("CREATE TABLE ")]
        self.assertEqual(names, ["visitor_stats_day_totals", "visitor_stats_lot_totals"])
        self.assertNotIn("visitor_stats_daily", DDL)
    def test_fail_closed_non_destructive(self):
        self.assertEqual(len([s for s in DDL.split(";") if s.strip()]), 2)
        for forbidden in ("IF NOT EXISTS", "DROP ", "ALTER ", "DELETE ", "TRUNCATE ",
                          "UPDATE ", "INSERT ", "REPLACE ", "CREATE USER"):
            self.assertNotIn(forbidden, DDL.upper())
        self.assertEqual(DDL.count("ENGINE=InnoDB"), 2)
    def test_aggregate_keys_and_columns(self):
        self.assertIn("PRIMARY KEY (visit_date)", DDL)
        self.assertIn("PRIMARY KEY (visit_date, lot_id)", DDL)
        for col in ("visitor_days BIGINT UNSIGNED", "views BIGINT UNSIGNED"):
            self.assertEqual(sum(line.strip().startswith(col) for line in DDL.splitlines()), 2)
        self.assertIn("lot_views BIGINT UNSIGNED NOT NULL", DDL)
        self.assertIn("lot_id BIGINT UNSIGNED NOT NULL", DDL)

if __name__ == "__main__":
    unittest.main()
