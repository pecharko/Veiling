"""B5i CLI regressions: no network, no remote writes, injected collector."""
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_b5i_readonly_cli import main, report_from_snapshot

SHA = "a" * 40
PIN = {"release_id": "demo", "release_sha": SHA, "release_run_id": 12,
       "expected_paths": ["catalogus-public/index.php"],
       "authorized_main_sha": SHA, "observation": None,
       "b4_run_id": 45, "b4_execution_sha": SHA}


class B5iCliTests(unittest.TestCase):
    def test_exact_pins_forwarded_no_hidden_actions(self):
        seen = []
        def fake(**kwargs):
            seen.append(kwargs)
            return {"status": "INCOMPLETE", "fully_accepted": False,
                    "independent_provenance_authenticated": False,
                    "dispatch_started": False}
        result = report_from_snapshot(PIN.copy(), "read-token", collect_fn=fake)
        self.assertEqual(result["status"], "INCOMPLETE")
        self.assertEqual(len(seen), 1)
        self.assertEqual(seen[0]["token"], "read-token")
        self.assertEqual(set(seen[0]) - {"token"}, set(PIN))

    def test_extra_and_missing_fields_fail_closed(self):
        for snapshot in ({**PIN, "deployment_authorized": True},
                         {k: v for k, v in PIN.items() if k != "b4_run_id"}):
            with self.assertRaises(ValueError):
                report_from_snapshot(snapshot, "read-token", collect_fn=lambda **kw: {})

    def test_local_cli_writes_report_using_injected_reader(self):
        with tempfile.TemporaryDirectory() as d:
            input_path = Path(d) / "pins.json"
            output_path = Path(d) / "report.json"
            input_path.write_text(json.dumps(PIN), encoding="utf-8")
            with patch.dict(os.environ, {"GITHUB_TOKEN": "read-token"}):
                self.assertEqual(main(["--input", str(input_path),
                                      "--output", str(output_path)],
                                      collect_fn=lambda **kw: {
                                          "status": "INCOMPLETE",
                                          "fully_accepted": False}), 0)
            self.assertEqual(json.loads(output_path.read_text())["status"], "INCOMPLETE")

    def test_missing_token_never_reads_or_writes(self):
        with tempfile.TemporaryDirectory() as d:
            output_path = Path(d) / "report.json"
            with patch.dict(os.environ, {"GITHUB_TOKEN": ""}):
                with self.assertRaises(SystemExit):
                    main(["--input", "not-found", "--output", str(output_path)],
                         collect_fn=lambda **kw: self.fail("unexpected read"))
            self.assertFalse(output_path.exists())


class B5jWorkflowContractTests(unittest.TestCase):
    def setUp(self):
        self.workflow = (Path(__file__).resolve().parents[1] /
                         ".github/workflows/b5b-offline-report.yml").read_text()

    def test_workflow_has_only_read_permissions(self):
        self.assertIn("permissions:\n  contents: read\n  actions: read", self.workflow)
        self.assertNotIn("environment: production", self.workflow)
        self.assertNotIn("secrets.OVH", self.workflow)

    def test_github_mode_is_explicit_opt_in_and_main_only(self):
        self.assertIn("default: offline", self.workflow)
        self.assertIn("if: inputs.report_mode == 'github-readonly'", self.workflow)
        self.assertIn("github.ref == 'refs/heads/main'", self.workflow)
        self.assertIn("controller_b5i_readonly_cli.py --input", self.workflow)

    def test_existing_offline_mode_is_preserved(self):
        self.assertIn("if: inputs.report_mode == 'offline'", self.workflow)
        self.assertIn("controller_b5b_offline_report.py --input", self.workflow)
        self.assertIn("persist-credentials: false", self.workflow)


if __name__ == "__main__":
    unittest.main()
