"""Offline contract for separate, owner-bound protected v8 release workflow.

No GitHub/OVH credentials or dispatch. Historical workflows remain untouched.
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/snapshot-v8-protected-release.yml"
CONTROLLER = ROOT / "scripts/deploy_snapshot_v8.py"
SHARED = ROOT / "scripts/shared_ovh_release.py"
HISTORICAL = (
    ROOT / ".github/workflows/snyk-snapshot-delete-protected-release.yml",
    ROOT / ".github/workflows/artist-publication-snapshot-release.yml",
    ROOT / ".github/workflows/lot-audit-snapshot-v7-release.yml",
)


class SnapshotV8WorkflowSecurityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")
        cls.controller = CONTROLLER.read_text(encoding="utf-8")
        cls.shared = SHARED.read_text(encoding="utf-8")
        cls.validate, cls.deploy = cls.workflow.split("\n  deploy:\n", 1)

    def test_pr_checks_are_offline_only_and_fail_without_baseline(self):
        s = self.validate
        self.assertIn("if: github.event_name == 'pull_request'", s)
        self.assertIn("permissions:\n  contents: read", s)
        self.assertNotIn("pull_request_target:", s)
        self.assertNotIn("\n  push:", s)
        self.assertNotIn("secrets.", s)
        self.assertNotIn("environment: production", s)
        self.assertNotIn("deploy_snapshot_v8 deploy", s)
        self.assertIn("deploy_snapshot_v8_test.py", s)
        self.assertIn("snapshot_v8_protected_workflow_test.py", s)
        self.assertIn("snapshot_v8_review_test.py", s)
        self.assertIn("scripts.snapshot_v8_review", s)
        self.assertIn("deploy_snapshot_v8 plan", s)
        self.assertIn("authenticated pinned-hostkey", s)
        self.assertIn("actionlint@v1.7.7", s)
        self.assertIn("php -l private/snapshots.php", s)
        self.assertIn("php -l catalogus-public/admin/backup.php", s)

    def test_prod_only_explicit_owner_and_external_backup_gate(self):
        s = self.deploy
        self.assertIn("if: github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main'", s)
        self.assertIn("environment: production", s)
        self.assertIn("SNAPSHOT-V8-DEPLOY-snapshot-v8-catalog-only-20261115-$GITHUB_SHA", s)
        self.assertIn("SNAPSHOT-V8-FULL-BACKUP-VERIFIED-20261115", s)
        self.assertIn("git fetch --no-tags origin main", s)
        self.assertIn('test "$(git rev-parse FETCH_HEAD)" = "$GITHUB_SHA"', s)
        self.assertIn('test "$GITHUB_REPOSITORY" = "pecharko/Veiling"', s)
        self.assertIn("GITHUB_WORKFLOW_REF", s)
        self.assertIn("SNAPSHOT_V8_BACKUP_READY: ${{ inputs.backup_ready }}", s)
        checks = s.index("Check immutable main, exact owner confirmation")
        plan = s.index("deploy_snapshot_v8 plan")
        client = s.index("Install pinned SFTP client")
        write = s.index("deploy_snapshot_v8 deploy")
        self.assertLess(checks, plan)
        self.assertLess(plan, client)
        self.assertLess(client, write)

    def test_no_legacy_fallback_protected_allowlist_or_auto_deploy(self):
        s = self.workflow
        self.assertEqual(s.count("deploy_snapshot_v8 deploy"), 1)
        self.assertIn("persist-credentials: false", s)
        self.assertIn("concurrency:", s)
        self.assertIn("'ovh-production-deploy'", s)
        self.assertIn("cancel-in-progress: false", s)
        self.assertIn("OVH_SFTP_KNOWN_HOSTS: ${{ secrets.OVH_SFTP_KNOWN_HOSTS }}", s)
        self.assertIn("paramiko==3.5.1", s)
        self.assertNotIn("deploy_artist_publication_snapshot deploy", s)
        self.assertNotIn("deploy_snyk_snapshot_delete deploy", s)
        self.assertNotIn("deploy_lot_audit_snapshot_v7 deploy", s)
        self.assertNotIn("schedule:", s)
        self.assertNotIn("workflow_run:", s)
        self.assertNotIn("contents: write", s)
        self.assertIn('RELEASE_ID = "snapshot-v8-catalog-only-20261115"', self.controller)
        self.assertIn('"private/snapshots.php", "catalogus-public/admin/backup.php"', self.controller)
        self.assertIn('"private/snapshots"', self.shared)
        self.assertIn("any(name.startswith(blocked) for blocked in PROTECTED)", self.shared)
        self.assertIn("engine.load_manifest = guarded_load", self.controller)

    def test_rollback_evidence_and_exact_two_file_source_baselines(self):
        s = self.deploy
        self.assertIn("deployment-evidence.json", s)
        self.assertIn("deployment-backups/", s)
        self.assertIn("if: always()", s)
        self.assertIn("snapshot-v8-rollback-${{ github.run_id }}", s)
        self.assertIn("snapshot-v8-evidence-${{ github.run_id }}", s)
        self.assertIn('manifest["files"]', self.controller)
        self.assertIn('entry["remote_sha256"]', self.controller)
        self.assertIn("engine.HASH.fullmatch(remote_hash)", self.controller)
        self.assertIn("engine.digest(data) != source_hash", self.controller)
        self.assertIn("review_source(data.decode(", self.controller)
        for marker in (
            "BASELINE OK", "ROLLBACK VERIFIED", "VERIFIED", "if actual != expected[name]",
            "read_remote(sftp", "client.load_host_keys", "RejectPolicy()",
        ):
            self.assertIn(marker, self.shared)

    def test_only_new_release_paths_and_separate_workflow(self):
        self.assertTrue(WORKFLOW.is_file())
        self.assertTrue(all(path.is_file() for path in HISTORICAL))
        self.assertEqual(self.workflow.count("workflow_dispatch:"), 1)
        self.assertIn("if-no-files-found: warn", self.workflow)


if __name__ == "__main__":
    unittest.main()
