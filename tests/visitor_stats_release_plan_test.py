#!/usr/bin/env python3
"""Regression checks for the offline visitor statistics release plan."""
import json
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/plan_visitor_stats_release.py"

def run(*args):
    return subprocess.run([sys.executable, str(SCRIPT), *args], cwd=ROOT, text=True, capture_output=True)

class OfflineVisitorPlanTests(unittest.TestCase):
    def test_current_head_manifest(self):
        sha = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip()
        result = run("--release-sha", sha)
        self.assertEqual(result.returncode, 0, result.stderr)
        plan = json.loads(result.stdout)
        self.assertEqual(plan["release_sha"], sha)
        self.assertEqual(plan["allowlist_count"], 5)
        self.assertEqual(len(plan["files"]), 5)
        self.assertTrue(all(item["php_lint"] == "pass" for item in plan["files"]))
        self.assertFalse(plan["upload_allowed"])
        self.assertFalse(plan["database_mutation_allowed"])
        self.assertFalse(plan["production_verified"])
        self.assertNotIn("private/config.php", [item["path"] for item in plan["files"]])
        self.assertNotIn("database/visitor_stats.sql", [item["path"] for item in plan["files"]])

    def test_rejects_wrong_sha(self):
        result = run("--release-sha", "0" * 40)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("STOP", result.stderr)

    def test_rejects_invalid_sha(self):
        for value in ("", "main", "deadbeef", "../main", "z" * 40):
            with self.subTest(value=value):
                self.assertNotEqual(run("--release-sha", value).returncode, 0)

    def test_script_contains_no_remote_actions(self):
        script = SCRIPT.read_text()
        for name in ("paramiko", "pysftp", "ftplib", "requests.", "urllib.request", "DELETE FROM", "CREATE TABLE", "ssh ", "sftp "):
            with self.subTest(name=name):
                self.assertNotIn(name, script)

if __name__ == "__main__":
    unittest.main()
