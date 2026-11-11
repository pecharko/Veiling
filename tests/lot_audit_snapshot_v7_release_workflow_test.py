"""Fail-closed static regression checks for the dedicated v7 production workflow.

Review only: tests inspect YAML text and never dispatch workflows or access OVH.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
V7 = ROOT / ".github/workflows/lot-audit-snapshot-v7-release.yml"
BANK = ROOT / ".github/workflows/bank-snapshot-release.yml"
MANIFEST = ROOT / "releases/lot-audit-snapshot-v7-20261111.json"


class LotAuditSnapshotV7ReleaseWorkflowTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = V7.read_text(encoding="utf-8")
        cls.bank = BANK.read_text(encoding="utf-8")
        cls.review, cls.deploy = cls.workflow.split("\n  deploy:\n", 1)

    def test_review_has_no_production_credentials_or_deploy(self):
        self.assertIn("if: github.event_name == 'pull_request'", self.review)
        self.assertIn("persist-credentials: false", self.review)
        self.assertIn("permissions:\n  contents: read", self.review)
        self.assertIn("scripts.deploy_lot_audit_snapshot_v7 plan", self.review)
        self.assertIn("lot_audit_snapshot_v7_release_workflow_test.py", self.review)
        self.assertIn("actionlint@v1.7.7", self.review)
        self.assertNotIn("secrets.", self.review)
        self.assertNotIn("scripts.deploy_lot_audit_snapshot_v7 deploy", self.review)
        self.assertNotIn("environment: production", self.review)

    def test_dispatch_is_exactly_main_and_owner_bound(self):
        self.assertIn("workflow_dispatch:", self.review)
        self.assertNotRegex(self.workflow, r"(?m)^  push:")
        self.assertNotRegex(self.workflow, r"(?m)^  schedule:")
        self.assertIn("if: github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main'", self.deploy)
        self.assertIn("environment: production", self.deploy)
        self.assertIn("cancel-in-progress: false", self.workflow)
        self.assertIn("'ovh-production-deploy'", self.workflow)
        self.assertIn('test "$(git rev-parse FETCH_HEAD)" = "$GITHUB_SHA"', self.deploy)
        self.assertIn(
            'test "$DEPLOY_CONFIRMATION" = "LOT-AUDIT-SNAPSHOT-V7-DEPLOY-lot-audit-snapshot-v7-20261111-$GITHUB_SHA"',
            self.deploy,
        )
        self.assertIn(
            'test "$LOT_AUDIT_SCHEMA_READY" = \'LOT-AUDIT-SCHEMA-V7-VERIFIED-20261111\'',
            self.deploy,
        )

    def test_hostkey_drift_backup_rollback_evidence(self):
        for required in (
            "OVH_SFTP_KNOWN_HOSTS: ${{ secrets.OVH_SFTP_KNOWN_HOSTS }}",
            "OVH_SFTP_HOST: ${{ secrets.OVH_SFTP_HOST }}",
            "OVH_SFTP_BASE: ${{ vars.OVH_SFTP_BASE }}",
            "paramiko==3.5.1",
            "python3 -B -m scripts.deploy_lot_audit_snapshot_v7 plan",
            "python3 -B -m scripts.deploy_lot_audit_snapshot_v7 deploy",
            "deployment-evidence.json",
            "deployment-backups/",
            "if: always()",
            "retention-days: 7",
            "php -l private/snapshots.php",
        ):
            with self.subTest(required=required):
                self.assertIn(required, self.deploy)
        self.assertEqual(
            self.deploy.count("python3 -B -m scripts.deploy_lot_audit_snapshot_v7 deploy"), 1
        )

    def test_historical_bank_stays_separate(self):
        self.assertIn("BANK-SNAPSHOT-DEPLOY-bank-snapshot-20261110-$GITHUB_SHA", self.bank)
        self.assertIn("python3 -B -m scripts.deploy_bank_snapshot deploy", self.bank)
        self.assertNotIn("deploy_bank_snapshot", self.workflow)
        self.assertNotIn("bank-snapshot-20261110.json", self.workflow)
        self.assertTrue(MANIFEST.is_file())
        self.assertIn('private/snapshots.php', MANIFEST.read_text(encoding="utf-8"))

    def test_no_silent_bypass_or_wider_targets(self):
        self.assertNotIn("continue-on-error: true", self.workflow)
        self.assertNotIn("|| true", self.workflow)
        self.assertNotIn("workflow_call:", self.workflow)
        self.assertNotIn("write-all", self.workflow)
        self.assertNotRegex(self.workflow, r"(?m)^\s*contents:\s*write$")
        self.assertNotIn("deploy_bank_snapshot deploy", self.workflow)
        self.assertNotIn("scripts.shared_ovh_release deploy", self.workflow)
        self.assertNotIn("override", self.workflow.lower())


if __name__ == "__main__":
    unittest.main()
