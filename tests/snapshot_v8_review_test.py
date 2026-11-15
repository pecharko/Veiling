"""Negative offline regression tests: v8 review is never a deploy permission."""
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from scripts.snapshot_v8_review import FORMAT, TABLES, review_source

SOURCE = ROOT / "private/snapshots.php"


class V8ReviewTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source = SOURCE.read_text(encoding="utf-8")

    def test_valid_v8_snapshot(self):
        review_source(self.source)

    def test_unknown_version_and_missing_table_fail_closed(self):
        for changed in (
            self.source.replace(FORMAT, "41lokeren-v9-unknown"),
            self.source.replace("'lot_categories'=>['id','name'],", ""),
            self.source.replace("'lot_categories','lot_sellers'", "'lot_sellers'"),
            self.source.replace("'41lokeren-v8-catalog-only'", "'41lokeren-v7-lot-audit'", 1),
        ):
            with self.subTest(version=changed[:25]), self.assertRaises(ValueError):
                review_source(changed)

    def test_authentication_import_and_delete_fail_closed(self):
        for table in ("admins", "login_attempts", "admin_invitations", "admin_password_resets"):
            with self.subTest(table=table):
                with self.assertRaises(ValueError):
                    review_source(self.source + "\n '" + table + "'=>['id'],\n")
                with self.assertRaises(ValueError):
                    review_source(self.source + "\n DELETE FROM " + table + ";\n")

    def test_restore_order_and_checksum_gates_fail_closed(self):
        for marker in (
            "foreach(['lot_images','seller_images','lots','lot_sellers','lot_categories','settings'] as $table)",
            "snap_require_referenced_photos(",
            "restore_journal_",
        ):
            with self.subTest(marker=marker), self.assertRaises(ValueError):
                review_source(self.source.replace(marker, "REMOVED"))

    def test_historical_deploy_jobs_remain_exclusive_and_fail_closed(self):
        workflows = (
            ("bank-snapshot-release.yml", "BANK-SNAPSHOT-DEPLOY-bank-snapshot-20261110-$GITHUB_SHA", "scripts.deploy_bank_snapshot"),
            ("lot-audit-snapshot-v7-release.yml", "LOT-AUDIT-SNAPSHOT-V7-DEPLOY-lot-audit-snapshot-v7-20261111-$GITHUB_SHA", "scripts.deploy_lot_audit_snapshot_v7"),
            ("artist-slogan-snapshot-release.yml", "ARTIST-SLOGAN-SNAPSHOT-DEPLOY-artist-slogan-snapshot-20261112-$GITHUB_SHA", "scripts.deploy_artist_slogan_snapshot"),
        )
        for filename, confirmation, adapter in workflows:
            with self.subTest(workflow=filename):
                src = (ROOT / ".github/workflows" / filename).read_text(encoding="utf-8")
                validate, deploy = src.split("\\n  deploy:\\n", 1)
                self.assertIn("if: github.event_name == 'pull_request'", validate)
                self.assertIn("scripts.snapshot_v8_review", validate)
                self.assertNotIn("environment: production", validate)
                self.assertNotIn("secrets.", validate)
                self.assertNotIn("scripts.snapshot_v8_review", deploy)
                self.assertNotIn("snapshot_v8_review_test.py", deploy)
                self.assertIn("if: github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main'", deploy)
                self.assertIn(confirmation, deploy)
                self.assertIn("git fetch --no-tags origin main", deploy)
                self.assertIn("OVH_SFTP_KNOWN_HOSTS", deploy)
                self.assertIn("environment: production", deploy)
                self.assertIn(adapter + " plan", deploy)
                self.assertIn(adapter + " deploy", deploy)
                self.assertIn("deployment-evidence.json", deploy)
                self.assertIn("deployment-backups/", deploy)
                self.assertIn("if: always()", deploy)

    def test_no_deploy_network_or_authorization(self):
        module = (ROOT / "scripts/snapshot_v8_review.py").read_text(encoding="utf-8")
        self.assertNotIn("paramiko", module)
        self.assertNotIn("subprocess.run(", module)
        self.assertNotIn("workflow_dispatch", module)
        self.assertNotIn("DEPLOY_CONFIRMATION", module)
        self.assertNotIn("requests.", module)


if __name__ == "__main__":
    unittest.main()
