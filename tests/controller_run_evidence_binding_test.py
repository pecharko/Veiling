#!/usr/bin/env python3
"""Offline adversarial fixtures for Actions release evidence binding."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_run_evidence_binding import inspect_run, REQUIRED_STEPS, WORKFLOW

SHA = "a" * 40
RUN = {"id": 123, "head_sha": SHA, "path": WORKFLOW,
       "event": "workflow_dispatch", "head_branch": "main",
       "status": "completed", "conclusion": "success"}
JOB = {"name": "release", "status": "completed", "conclusion": "success",
       "steps": [{"name": n, "status": "completed", "conclusion": "success"}
                 for n in REQUIRED_STEPS]}


class BindingTest(unittest.TestCase):
    def check(self, run=RUN, jobs=None):
        return inspect_run(run, [JOB] if jobs is None else jobs,
                           run_id=123, release_sha=SHA)

    def test_success_is_not_remote_proof(self):
        self.assertEqual(self.check()["reason"],
                         "actions_success_not_independent_remote_proof")

    def test_missing_run(self):
        self.assertEqual(self.check(None)["status"], "INCOMPLETE")

    def test_sha_mismatch(self):
        self.assertEqual(self.check({**RUN, "head_sha": "b" * 40})["status"], "FAILED")

    def test_wrong_event_or_branch(self):
        for changed in ({"event": "push"}, {"head_branch": "other"}):
            self.assertEqual(self.check({**RUN, **changed})["status"], "FAILED")

    def test_cancelled(self):
        self.assertEqual(self.check({**RUN, "conclusion": "cancelled"})["status"], "FAILED")

    def test_pending(self):
        self.assertEqual(self.check({**RUN, "status": "queued"})["status"], "PENDING")

    def test_missing_duplicate_job(self):
        for jobs in ([], [JOB, JOB]):
            self.assertEqual(self.check(jobs=jobs)["status"], "INCOMPLETE")

    def test_missing_step(self):
        self.assertEqual(self.check(jobs=[{**JOB, "steps": JOB["steps"][:-1]}])["status"],
                         "INCOMPLETE")

    def test_failed_step(self):
        steps = [{**x, "conclusion": "failure"} if x["name"] == REQUIRED_STEPS[4]
                 else x for x in JOB["steps"]]
        self.assertEqual(self.check(jobs=[{**JOB, "steps": steps}])["status"], "FAILED")

    def test_duplicate_step(self):
        steps = JOB["steps"] + [JOB["steps"][0]]
        self.assertEqual(self.check(jobs=[{**JOB, "steps": steps}])["status"],
                         "INCOMPLETE")

    def test_invalid_identity(self):
        with self.assertRaises(ValueError):
            inspect_run(RUN, [JOB], run_id=True, release_sha=SHA)


if __name__ == "__main__":
    unittest.main()
