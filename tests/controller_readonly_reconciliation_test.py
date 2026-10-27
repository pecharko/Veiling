#!/usr/bin/env python3
"""Offline fail-closed fixtures for read-only combined release review."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_readonly_reconciliation import reconcile
from controller_run_evidence_binding import REQUIRED_STEPS, WORKFLOW

SHA = "a" * 40
DIGEST = "b" * 64
PATH = "catalogus-public/index.php"
RUN = {"id": 123, "head_sha": SHA, "path": WORKFLOW,
       "event": "workflow_dispatch", "head_branch": "main",
       "status": "completed", "conclusion": "success"}
JOBS = [{"name": "release", "status": "completed", "conclusion": "success",
         "steps": [{"name": n, "status": "completed", "conclusion": "success"}
                   for n in REQUIRED_STEPS]}]
DOCUMENT = {"schema": 1, "release_id": "test-release", "release_sha": SHA,
            "run_id": 123, "status": "VERIFIED",
            "files": [{"path": PATH, "source_sha256": DIGEST,
                       "baseline_sha256": "ABSENT", "readback_sha256": DIGEST}],
            "rollback_artifact": 502, "browser_acceptance": "PENDING"}
ARTIFACTS = [
    {"name": "shared-ovh-evidence-123", "id": 501, "expired": False,
     "workflow_run": {"id": 123}},
    {"name": "shared-ovh-rollback-123", "id": 502, "expired": False,
     "workflow_run": {"id": 123}},
]


class ReconciliationTest(unittest.TestCase):
    def examine(self, run=RUN, jobs=JOBS, document=DOCUMENT, artifacts=ARTIFACTS):
        return reconcile(run, jobs, document, artifacts,
                         run_id=123, release_sha=SHA, expected_paths=[PATH])

    def test_coherent_metadata_still_not_productieacceptatie(self):
        x = self.examine()
        self.assertFalse(x["fully_accepted"])
        self.assertFalse(x["dispatch_started"])
        self.assertEqual(x["stages"]["deployment"]["status"], "INCOMPLETE")
        self.assertEqual(x["review"]["independent_remote_readback"], "NOT_VERIFIED")

    def test_provenance_never_infers_independent_ovh_or_acceptance(self):
        x = self.examine()
        p = x["provenance"]
        self.assertEqual(p["github_actions"]["source"], "github_api_run_jobs")
        self.assertEqual(p["artifact_and_rollback"]["source"],
                         "github_api_artifact_inventory_and_runner_json")
        self.assertFalse(p["artifact_and_rollback"]["retention_7_days_independently_verified"])
        self.assertEqual(p["runner_reported_sftp_readback"]["status"],
                         "NOT_INDEPENDENTLY_VERIFIED")
        self.assertEqual(p["external_ovh_readback"]["status"], "NOT_VERIFIED")
        self.assertEqual(p["browser_acceptance"]["status"], "PENDING")
        self.assertFalse(x["fully_accepted"])

    def test_failed_run_preserves_failure_provenance(self):
        x = self.examine(run={**RUN, "conclusion": "failure"})
        self.assertEqual(x["provenance"]["github_actions"]["status"], "FAILED")
        self.assertFalse(x["fully_accepted"])

    def test_missing_artifact_cannot_be_hidden_by_green_run(self):
        x = self.examine(artifacts=[])
        self.assertEqual(x["review"]["artifact_metadata"]["reason"],
                         "missing_or_duplicate_artifact")
        self.assertFalse(x["fully_accepted"])

    def test_bad_sha_cannot_be_hidden_by_artifacts(self):
        x = self.examine(run={**RUN, "head_sha": "c" * 40})
        self.assertEqual(x["review"]["run_metadata"]["status"], "FAILED")
        self.assertFalse(x["fully_accepted"])

    def test_cancelled_run(self):
        x = self.examine(run={**RUN, "conclusion": "cancelled"})
        self.assertEqual(x["review"]["run_metadata"]["status"], "FAILED")

    def test_missing_browser_acceptance_never_inferred(self):
        x = self.examine()
        self.assertEqual(x["stages"]["browser_acceptance"]["status"], "INCOMPLETE")
        self.assertEqual(x["review"]["independent_browser_acceptance"], "PENDING")


if __name__ == "__main__":
    unittest.main()
