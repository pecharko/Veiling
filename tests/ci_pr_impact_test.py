"""Isolated CI path-selection contract tests (no MySQL, PHP or GitHub API)."""
import io
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
import ci_pr_impact as impact


class ImpactTests(unittest.TestCase):
    def test_phase_c_installation_proof_skips_unrelated_mysql_http_suites(self):
        self.assertEqual(impact.classify([
            "scripts/phase_c_c2_installation_proof.py",
            "tests/phase_c_c2_installation_proof_test.py",
        ]), (False, False))

    def test_phase_c_mixed_with_php_or_infrastructure_runs_both(self):
        for path in ("catalogus-public/admin/kavel.php",
                     "private/snapshots.php",
                     "scripts/ci_pr_impact.py",
                     ".github/workflows/kavel-integration.yml",
                     ".github/workflows/php-lint.yml"):
            with self.subTest(path=path):
                self.assertEqual(impact.classify([
                    "scripts/phase_c_c2_installation_proof.py",
                    path,
                ]), (True, True))

    def test_readonly_python_script_only_skips_heavy_integrations(self):
        self.assertEqual(impact.classify([
            "scripts/shared_ovh_controller_evidence.py",
            "tests/shared_ovh_controller_evidence_test.py"
        ]), (False, False))

    def test_readonly_release_evidence_scripts_only_skip_integrations(self):
        self.assertEqual(impact.classify([
            "scripts/ovh_release_evidence_discovery.py",
            "tests/ovh_release_evidence_discovery_test.py",
        ]), (False, False))

    def test_verified_controller_and_its_regressions_skip_unrelated_php_suites(self):
        self.assertEqual(impact.classify([
            "scripts/verified_deploy_controller.py",
            "tests/verified_deploy_controller_test.py",
        ]), (False, False))
        for path in ("scripts/verified_deploy_controller.py",
                     "tests/verified_deploy_controller_test.py"):
            with self.subTest(path=path):
                self.assertEqual(impact.classify([path]), (False, False))

    def test_verified_controller_mixed_with_app_or_test_infra_runs_both(self):
        for path in ("private/lot_form.php",
                     "migrations/issue-289-lot-statuses.sql",
                     "tests/kavel_http_integration.php",
                     "tests/snapshot_integration.php",
                     "tests/kavel_integration_setup.php",
                     ".github/workflows/kavel-integration.yml",
                     "scripts/ci_pr_impact.py",
                     "scripts/shared_ovh_controller_adapter.py"):
            with self.subTest(path=path):
                self.assertEqual(impact.classify([
                    "scripts/verified_deploy_controller.py",
                    "tests/verified_deploy_controller_test.py",
                    path,
                ]), (True, True))

    def test_release_manifest_and_docs_only_do_not_need_mysql_http(self):
        self.assertEqual(impact.classify([
            "release-requests/website-beheer.json",
            "releases/website-beheer.json",
            "docs/ci-automation.md",
            "README.md",
        ]), (False, False))

    def test_kavel_and_photo_changes_require_both_conservatively(self):
        for path in (
            "catalogus-public/admin/kavel.php",
            "private/lot_form.php",
            "catalogus-public/uploads/example.webp",
            "tests/kavel_http_integration.php",
        ):
            with self.subTest(path=path):
                self.assertEqual(impact.classify([path]), (True, True))

    def test_snapshot_and_restore_changes_require_both(self):
        for path in (
            "private/snapshots.php",
            "private/catalog_reset.php",
            "catalogus-public/admin/backup.php",
            "tests/snapshot_integration.php",
        ):
            with self.subTest(path=path):
                self.assertEqual(impact.classify([path]), (True, True))

    def test_shared_php_and_database_schema_require_both(self):
        for path in (
            "private/bootstrap.php",
            "catalogus-public/index.php",
            "database/lot_requirements_185_migration.sql",
        ):
            with self.subTest(path=path):
                self.assertEqual(impact.classify([path]), (True, True))

    def test_test_dependencies_or_workflows_require_both(self):
        for path in (
            "scripts/ci_pr_impact.py",
            "tests/ci_pr_impact_test.py",
            ".github/workflows/php-lint.yml",
            ".github/workflows/kavel-integration.yml",
            ".github/workflows/snapshot-integration.yml",
            "tests/kavel_integration_setup.php",
        ):
            with self.subTest(path=path):
                self.assertEqual(impact.classify([path]), (True, True))

    def test_owner_authorization_adapter_requires_real_integration_suites(self):
        for path in (
            "scripts/shared_ovh_controller_adapter.py",
            "tests/shared_ovh_controller_adapter_test.py",
        ):
            with self.subTest(path=path):
                self.assertEqual(impact.classify([path]), (True, True))

    def test_mixed_impact_can_never_skip_functional_tests(self):
        self.assertEqual(impact.classify([
            "docs/example.md",
            "scripts/shared_ovh_controller_adapter.py",
            "private/bootstrap.php",
        ]), (True, True))

    def test_unknown_scripts_run_both_by_default(self):
        self.assertEqual(impact.classify(["scripts/new_unreviewed_tool.py"]),
                         (True, True))
        self.assertEqual(impact.classify(["tests/new_test.py"]),
                         (True, True))

    def test_documentation_masquerading_as_code_runs_both(self):
        self.assertEqual(impact.classify(["docs/something.php"]), (True, True))
        self.assertEqual(impact.classify(["releases/odd.py"]), (True, True))

    def test_dotted_hidden_and_reserved_paths_fail_closed(self):
        for path in ("/absolute", "docs/../README.md", "docs//x.md",
                     "docs/\nentry.md", "docs\\entry.md", ".env"):
            with self.subTest(path=path):
                with self.assertRaises(ValueError):
                    impact.classify([path])

    def test_duplicate_empty_or_excessive_path_inputs_fail(self):
        for paths in ([], ["docs/a.md", "docs/a.md"],
                      ["docs/a.md"] * (impact.MAX_PATHS + 1), [""]):
            with self.subTest(length=len(paths)):
                with self.assertRaises(ValueError):
                    impact.classify(paths)

    def test_roundtrip_git_diff_path_nul_delimiter(self):
        raw = b"docs/a.md\0scripts/shared_ovh_controller_evidence.py\0"
        self.assertEqual(impact.parse_git_diff(raw),
                         ["docs/a.md", "scripts/shared_ovh_controller_evidence.py"])
        self.assertEqual(impact.classify(impact.parse_git_diff(raw)),
                         (False, False))

    def test_corrupt_or_unbounded_git_diff_fails(self):
        for raw in (b"", b"docs/a.md\n", b"\xff\0",
                    b"docs/a.md\0\0", b"x" * (impact.MAX_INPUT + 1)):
            with self.subTest(length=len(raw)):
                with self.assertRaises(ValueError):
                    paths = impact.parse_git_diff(raw)
                    impact.classify(paths)

    def test_no_inference_of_success_from_unknown_or_absent_paths(self):
        with self.assertRaises(ValueError):
            impact.classify(None)
        self.assertEqual(impact.classify(["some/unknown/path"]), (True, True))


if __name__ == "__main__":
    unittest.main()
