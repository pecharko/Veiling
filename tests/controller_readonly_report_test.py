#!/usr/bin/env python3
"""Offline adversarial fixtures; no network, production, or credentials."""
import unittest
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_readonly_report import assess_run, report, as_json, as_text

SHA = "a" * 40
WORKFLOW = ".github/workflows/shared-ovh-release.yml"
RUN = {"id": 123, "head_sha": SHA, "path": WORKFLOW,
       "status": "completed", "conclusion": "success"}
JOB = {"name": "deploy", "status": "completed", "conclusion": "success",
       "head_sha": SHA}


class EvidenceReportTest(unittest.TestCase):
    def inspect(self, run=None, jobs=None):
        return assess_run(RUN if run is None else run,
                          [JOB] if jobs is None else jobs,
                          expected_sha=SHA, expected_workflow=WORKFLOW,
                          required_job="deploy")

    def test_success_does_not_imply_acceptance(self):
        good = self.inspect()
        self.assertEqual(good["status"], "VERIFIED")
        result = report({"deployment": good})
        self.assertFalse(result["fully_accepted"])
        for key in ("readback", "rollback", "browser_acceptance"):
            self.assertEqual(result["stages"][key]["status"], "INCOMPLETE")
        self.assertFalse(result["dispatch_started"])

    def test_pending(self):
        self.assertEqual(self.inspect({**RUN, "status": "queued",
                                       "conclusion": None})["status"], "PENDING")

    def test_sha_drift(self):
        self.assertEqual(self.inspect({**RUN, "head_sha": "b" * 40})["status"], "FAILED")

    def test_workflow_mismatch(self):
        self.assertEqual(self.inspect({**RUN, "path": WORKFLOW + ".bak"})["status"], "FAILED")

    def test_failed_or_cancelled(self):
        for conclusion in ("failure", "cancelled", "skipped", None):
            with self.subTest(conclusion=conclusion):
                self.assertEqual(self.inspect({**RUN, "conclusion": conclusion})["status"], "FAILED")

    def test_missing_or_duplicate_job(self):
        self.assertEqual(self.inspect(jobs=[])["status"], "INCOMPLETE")
        self.assertEqual(self.inspect(jobs=[JOB, JOB])["status"], "INCOMPLETE")

    def test_job_sha_drift(self):
        self.assertEqual(self.inspect(jobs=[{**JOB, "head_sha": "b" * 40}])["status"], "FAILED")

    def test_job_skipped(self):
        self.assertEqual(self.inspect(jobs=[{**JOB, "conclusion": "skipped"}])["status"], "FAILED")

    def test_broken_run_identification(self):
        self.assertEqual(self.inspect({**RUN, "id": True})["status"], "INCOMPLETE")

    def test_no_automatic_nvt_for_production(self):
        nvt = {"status": "NOT_APPLICABLE", "reason": "docs", "source": None}
        for key in ("deployment", "readback", "rollback", "browser_acceptance"):
            with self.subTest(stage=key), self.assertRaises(ValueError):
                report({key: nvt})

    def test_duplicate_unexpected_stage_rejected(self):
        with self.assertRaises(ValueError):
            report({"unexpected": self.inspect()})

    def test_explicit_acceptance_required(self):
        good = self.inspect()
        stages = {k: good for k in ("deployment", "readback", "rollback",
                                    "browser_acceptance")}
        self.assertTrue(report(stages)["fully_accepted"])
        self.assertIn('"read_only":true', as_json(stages))
        self.assertIn("rollback: VERIFIED", as_text(stages))

    def test_missing_data_fail_closed(self):
        self.assertEqual(assess_run(None, None, expected_sha=SHA,
                                   expected_workflow=WORKFLOW,
                                   required_job="deploy")["status"], "INCOMPLETE")


if __name__ == "__main__":
    unittest.main()
