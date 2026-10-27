"""B3.2 offline migration review: static safety gates, not an SQL engine."""
from pathlib import Path
import unittest

SQL = (Path(__file__).resolve().parents[1] / "database" /
       "controller_release_claims_migration.sql").read_text()

class ClaimMigrationReview(unittest.TestCase):
    def test_explicit_manual_migration_only(self):
        self.assertIn("NIET AUTOMATISCH UITVOEREN", SQL)
        self.assertIn("ENGINE=InnoDB", SQL)
        self.assertIn("CREATE TABLE controller_release_claims", SQL)
        self.assertNotIn("CREATE DATABASE", SQL.upper())

    def test_independent_unique_constraints_and_stricter_release_scope(self):
        self.assertIn("UNIQUE KEY uq_claim_comment (repository, issue_id, comment_id)", SQL)
        self.assertIn("UNIQUE KEY uq_claim_release_sha (repository, release_id, release_sha)", SQL)
        self.assertIn("UNIQUE KEY uq_claim_release_id (repository, release_id)", SQL)

    def test_no_migration_writes_except_create_table(self):
        executable = "\n".join(line for line in SQL.splitlines()
                               if not line.lstrip().startswith("--")).upper()
        for banned in ("DROP ", "TRUNCATE ", "DELETE ", "INSERT ", "UPDATE ",
                       "GRANT ", "ALTER USER ", "CREATE USER ", "CREATE DATABASE "):
            self.assertNotIn(banned, executable)

    def test_consistent_metadata(self):
        self.assertIn("CHAR(40)", SQL)
        self.assertIn("CHARACTER SET ascii COLLATE ascii_bin", SQL)
        self.assertIn("workflow_run_id BIGINT UNSIGNED NULL", SQL)

if __name__ == "__main__":
    unittest.main()
