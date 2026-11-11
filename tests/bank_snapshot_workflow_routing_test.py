"""Contract for safe historical-bank vs v7 PR validation routing.

The historical bank deploy job intentionally stays unchanged. A successful
v7 review is never an OVH baseline, owner approval, or deployment.
"""
from pathlib import Path
import unittest


WORKFLOW = Path(".github/workflows/bank-snapshot-release.yml")


class BankSnapshotWorkflowRoutingTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")
        cls.review, cls.deploy = cls.workflow.split("\n  deploy:\n", 1)

    def test_historical_dispatch_gates_remain(self):
        prod = self.deploy
        for required in (
            "github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main'",
            "environment: production",
            'test "$(git rev-parse FETCH_HEAD)" = "$GITHUB_SHA"',
            "BANK-SNAPSHOT-DEPLOY-bank-snapshot-20261110-$GITHUB_SHA",
            "BANK-SCHEMA-VERIFIED-20261110",
            "python3 -B -m scripts.deploy_bank_snapshot plan",
            "python3 -B -m scripts.deploy_bank_snapshot deploy",
            "paramiko==3.5.1",
            "deployment-backups/",
            "deployment-evidence.json",
        ):
            with self.subTest(required=required):
                self.assertIn(required, prod)
        self.assertNotIn("deploy_lot_audit_snapshot_v7", prod)

    def test_review_branches_are_mutually_exclusive_and_fail_closed(self):
        review = self.review
        self.assertIn("python3 -B -m unittest discover -s tests -p deploy_bank_snapshot_test.py -v", review)
        self.assertIn("if hashlib.sha256(source).hexdigest() == expected:", review)
        self.assertIn("'scripts.deploy_bank_snapshot', 'plan'", review)
        self.assertIn("elif b'41lokeren-v7-lot-audit' in source:", review)
        self.assertIn("'deploy_lot_audit_snapshot_v7_test.py'", review)
        self.assertIn("'scripts.deploy_lot_audit_snapshot_v7', 'plan'", review)
        self.assertIn("raise SystemExit('Unrecognized protected snapshot source drift')", review)
        self.assertIn("Historical bank snapshot manifest missing", review)
        self.assertIn("Historical bank snapshot manifest invalid", review)
        self.assertIn("if v7_manifest.exists():", review)
        self.assertNotIn("workflow_dispatch:", review.split("jobs:", 1)[1])
        self.assertIn("permissions:\n  contents: read", review)
        self.assertIn("'private/snapshots.php'", review)


if __name__ == "__main__":
    unittest.main()
