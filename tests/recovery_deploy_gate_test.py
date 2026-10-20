#!/usr/bin/env python3
"""Offline regression checks for the intentionally non-deploying recovery gate.

These checks prevent accidental relaxation of the production stop condition.
They never contact OVH and never perform writes.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parent.parent
WORKFLOW = ROOT / ".github/workflows/catalog-recovery-controlled-deploy.yml"


class RecoveryGateTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.body = WORKFLOW.read_text(encoding="utf-8")

    def test_manual_trigger_only(self):
        self.assertRegex(self.body, r"(?m)^  workflow_dispatch:\s*$")
        self.assertNotRegex(self.body, r"(?m)^  (push|pull_request|schedule|workflow_run):")

    def test_no_production_credentials_or_environment(self):
        self.assertNotIn("secrets.", self.body)
        self.assertNotIn("environment: production", self.body)
        self.assertIn("contents: read", self.body)
        self.assertNotIn("deploy_ovh_sftp", self.body)

    def test_deploy_fails_before_checkout(self):
        stop = self.body.index("- name: Refuse production uploads")
        checkout = self.body.index("- name: Checkout exact selected main revision")
        self.assertLess(stop, checkout)
        section = self.body[stop:checkout]
        self.assertIn('if [ "$REQUESTED_MODE" != \'plan\' ]; then', section)
        self.assertRegex(section, r"(?m)^\s+exit 1\s*$")
        self.assertIn("No SFTP connection attempted", section)

    def test_plan_only_source_verification(self):
        self.assertIn("scripts/plan_catalog_recovery_release.py", self.body)
        self.assertIn('--release-sha "$RELEASE_SHA"', self.body)
        self.assertIn("persist-credentials: false", self.body)

    def test_no_extra_runtime_paths(self):
        from scripts.plan_catalog_recovery_release import FILES
        self.assertEqual(len(FILES), 8)
        self.assertEqual(FILES[:2], (
            "private/catalog_mutex.php", "private/restore_journal.php"))
        self.assertEqual(FILES[-1], "private/bootstrap.php")
        self.assertEqual(len(set(FILES)), 8)


if __name__ == "__main__":
    unittest.main()
