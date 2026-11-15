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

    def test_no_deploy_network_or_authorization(self):
        module = (ROOT / "scripts/snapshot_v8_review.py").read_text(encoding="utf-8")
        self.assertNotIn("paramiko", module)
        self.assertNotIn("subprocess.run(", module)
        self.assertNotIn("workflow_dispatch", module)
        self.assertNotIn("DEPLOY_CONFIRMATION", module)
        self.assertNotIn("requests.", module)


if __name__ == "__main__":
    unittest.main()
