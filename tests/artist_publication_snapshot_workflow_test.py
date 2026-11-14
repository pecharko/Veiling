"""Static offline safeguards for the artist-publication protected workflow.

Pure source analysis; no GitHub API, credentials, SFTP, database, or dispatch.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/artist-publication-snapshot-release.yml"
CONTROLLER = ROOT / "scripts/deploy_artist_publication_snapshot.py"
HISTORICAL = (
    ROOT / ".github/workflows/artist-slogan-snapshot-release.yml",
    ROOT / ".github/workflows/lot-audit-snapshot-v7-release.yml",
)


class ProtectedPublicationWorkflowContract(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source = WORKFLOW.read_text(encoding="utf-8")
        cls.controller = CONTROLLER.read_text(encoding="utf-8")

    def test_review_never_deploys_and_permissions_remain_read_only(self):
        s = self.source
        self.assertIn("permissions:\n  contents: read", s)
        self.assertIn("if: github.event_name == 'pull_request'", s)
        self.assertIn("if: github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main'", s)
        self.assertNotIn("contents: write", s)
        self.assertNotIn("pull_request_target:", s)
        self.assertNotIn("schedule:", s)
        self.assertNotIn("push:", s)
        self.assertNotIn("workflow_run:", s)
        self.assertIn("persist-credentials: false", s)
        self.assertIn("environment: production", s)

    def test_exact_owner_and_schema_verification_precede_any_network(self):
        deploy = self.source.split("\n  deploy:\n", 1)[1]
        self.assertIn('git fetch --no-tags origin main', deploy)
        self.assertIn('test "$(git rev-parse FETCH_HEAD)" = "$GITHUB_SHA"', deploy)
        self.assertIn('ARTIST-PUBLICATION-SNAPSHOT-DEPLOY-artist-publication-snapshot-20261114-$GITHUB_SHA', deploy)
        self.assertIn("ARTIST-PUBLICATION-SCHEMA-VERIFIED-20261114", deploy)
        checks = deploy.index("Check immutable main, exact owner confirmation")
        manifest = deploy.index("deploy_artist_publication_snapshot plan")
        client = deploy.index("Install pinned SFTP client")
        upload = deploy.index("deploy_artist_publication_snapshot deploy")
        self.assertLess(checks, manifest)
        self.assertLess(manifest, client)
        self.assertLess(client, upload)

    def test_never_falls_back_to_unprotected_or_historical_release(self):
        s = self.source
        self.assertEqual(s.count("deploy_artist_publication_snapshot deploy"), 1)
        self.assertIn("concurrency:\n  group:", s)
        self.assertIn("'ovh-production-deploy'", s)
        self.assertIn("cancel-in-progress: false", s)
        self.assertIn("paramiko==3.5.1", s)
        self.assertIn("OVH_SFTP_KNOWN_HOSTS:", s)
        self.assertIn("deployment-evidence.json", s)
        self.assertIn("deployment-backups/", s)
        self.assertNotIn("deploy_artist_slogan_snapshot deploy", s)
        self.assertNotIn("deploy_lot_audit_snapshot deploy", s)
        self.assertIn("private/snapshots.php", s)
        self.assertIn('TARGET = "private/snapshots.php"', self.controller)
        self.assertIn('RELEASE_ID = "artist-publication-snapshot-20261114"', self.controller)

    def test_validation_is_offline_and_runs_security_tests(self):
        validate = self.source.split("\n  validate:\n", 1)[1].split("\n  deploy:\n", 1)[0]
        self.assertIn("deploy_artist_publication_snapshot_test.py", validate)
        self.assertIn("artist_publication_snapshot_workflow_test.py", validate)
        self.assertIn("actionlint@v1.7.7", validate)
        self.assertIn("php -l private/snapshots.php", validate)
        self.assertNotIn("secrets.", validate)
        self.assertNotIn("SFTP_", validate)
        self.assertNotIn("deploy_artist_publication_snapshot plan", validate)
        self.assertNotIn("deploy_artist_publication_snapshot deploy", validate)

    def test_only_explicit_separate_workflow_is_introduced(self):
        self.assertTrue(WORKFLOW.is_file())
        self.assertTrue(all(p.is_file() for p in HISTORICAL))
        self.assertIn("workflow_dispatch:", self.source)
        self.assertIn("if-no-files-found: warn", self.source)


if __name__ == "__main__":
    unittest.main()
