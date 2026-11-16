"""Read-only contract tests for the new protected artist overview snapshot workflow.

These assertions never connect to OVH, run a deployment, write to the
production database, or trigger GitHub Actions.
"""
from pathlib import Path
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW_PATH = ROOT / ".github/workflows/artist-overview-snapshot-protected-release.yml"
CONTROLLER_PATH = ROOT / "scripts/deploy_artist_overview_snapshot.py"
MANIFEST_PATH = ROOT / "releases/artist-overview-snapshot-20261116.json"
HISTORICAL = (
    ROOT / ".github/workflows/artist-publication-snapshot-release.yml",
    ROOT / ".github/workflows/snapshot-v8-protected-release.yml",
)


class ArtistOverviewSnapshotWorkflowContract(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW_PATH.read_text(encoding="utf-8")
        cls.controller = CONTROLLER_PATH.read_text(encoding="utf-8")

    def test_minimal_permissions_and_no_implicit_deploy(self):
        s = self.workflow
        self.assertIn("permissions:\n  contents: read", s)
        self.assertIn("on:\n  pull_request:", s)
        self.assertIn("workflow_dispatch:", s)
        self.assertNotIn("pull_request_target:", s)
        self.assertNotIn("\n  push:", s)
        self.assertNotIn("\n  schedule:", s)
        self.assertNotIn("\n  workflow_run:", s)
        self.assertNotIn("contents: write", s)
        self.assertIn("persist-credentials: false", s)
        self.assertIn("if: github.event_name == 'pull_request'", s)
        self.assertIn("if: github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main'", s)
        self.assertIn("environment: production", s)

    def test_owner_backup_repo_workflow_and_immutable_main_all_required(self):
        deploy = self.workflow.split("\n  deploy:\n", 1)[1]
        for expected in (
            'test "$GITHUB_REPOSITORY" = \'pecharko/Veiling\'',
            'test "$GITHUB_WORKFLOW_REF" = \'pecharko/Veiling/.github/workflows/artist-overview-snapshot-protected-release.yml@refs/heads/main\'',
            'git fetch --no-tags origin main',
            'test "$(git rev-parse FETCH_HEAD)" = "$GITHUB_SHA"',
            'ARTIST-OVERVIEW-SNAPSHOT-DEPLOY-artist-overview-snapshot-20261116-$GITHUB_SHA',
            'ARTIST-OVERVIEW-FULL-BACKUP-VERIFIED-20261116',
            "ARTIST_OVERVIEW_FULL_BACKUP_READY:",
        ):
            self.assertIn(expected, deploy)
        self.assertIn('BACKUP_KEY = "ARTIST_OVERVIEW_FULL_BACKUP_READY"', self.controller)
        self.assertIn('BACKUP_ATTESTATION = "ARTIST-OVERVIEW-FULL-BACKUP-VERIFIED-20261116"', self.controller)
        self.assertIn('WORKFLOW_REF = "pecharko/Veiling/.github/workflows/artist-overview-snapshot-protected-release.yml@refs/heads/main"', self.controller)

    def test_security_checks_precede_sole_upload(self):
        deploy = self.workflow.split("\n  deploy:\n", 1)[1]
        ordered = (
            "Verify exact immutable main",
            "deploy_artist_overview_snapshot_test.py",
            "deploy_artist_overview_snapshot plan",
            "Install pinned SFTP client",
            "deploy_artist_overview_snapshot deploy",
        )
        positions = [deploy.index(item) for item in ordered]
        self.assertEqual(positions, sorted(positions))
        self.assertEqual(self.workflow.count("deploy_artist_overview_snapshot deploy"), 1)
        self.assertNotIn("shared_ovh_release.py deploy", self.workflow)
        self.assertNotIn("deploy_snapshot_v8.py deploy", self.workflow)
        self.assertIn('TARGET = "private/snapshots.php"', self.controller)
        self.assertIn('RELEASE_ID = "artist-overview-snapshot-20261116"', self.controller)
        self.assertIn("paramiko==3.5.1", deploy)
        self.assertIn("OVH_SFTP_KNOWN_HOSTS:", deploy)
        self.assertIn("deployment-evidence.json", deploy)
        self.assertIn("deployment-backups/", deploy)
        self.assertIn("retention-days: 7", deploy)
        self.assertIn("'ovh-production-deploy'", self.workflow)
        self.assertIn("cancel-in-progress: false", self.workflow)

    def test_pull_request_validation_remains_offline(self):
        validate = self.workflow.split("\n  validate:\n", 1)[1].split("\n  deploy:\n", 1)[0]
        for item in (
            "deploy_artist_overview_snapshot_test.py",
            "artist_overview_snapshot_workflow_test.py",
            "actionlint@v1.7.7",
            "py_compile scripts/deploy_artist_overview_snapshot.py",
            "deploy_artist_overview_snapshot plan",
            "php -l private/snapshots.php",
        ):
            self.assertIn(item, validate)
        for forbidden in ("secrets.", "OVH_SFTP_", "deploy_artist_overview_snapshot deploy", "workflow_dispatch:"):
            self.assertNotIn(forbidden, validate)

    def test_historical_workflows_and_protected_gate_remain_untouched(self):
        for path in HISTORICAL:
            self.assertTrue(path.is_file())
        self.assertTrue(MANIFEST_PATH.is_file())
        self.assertIn("private/snapshots", self.controller)
        self.assertNotIn("deploy_artist_publication_snapshot deploy", self.workflow)
        self.assertNotIn("deploy_snapshot_v8 deploy", self.workflow)

    def test_dormant_controller_does_not_contact_sftp_during_offline_plan(self):
        from scripts import deploy_artist_overview_snapshot as adapter
        with patch.object(adapter.engine, "connect", side_effect=AssertionError("unexpected SFTP connection")), \
             patch.object(adapter.engine, "run", side_effect=ValueError("offline stop")):
            with patch("sys.argv", ["controller", "plan"]):
                with self.assertRaisesRegex(ValueError, "offline stop"):
                    adapter.main()


if __name__ == "__main__":
    unittest.main()
