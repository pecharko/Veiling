#!/usr/bin/env python3
"""Offline fail-closed fixtures for read-only combined release review."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_readonly_reconciliation import reconcile, reconcile_independent_b4
from controller_b5c_github_evidence_test import B5hSingleReportTests, B5gGitHubMetadataTests, B5eUnifiedEvidenceTests, B5dRunIdentityTests, B5cTests  # offline B5c composition regressions
from controller_b4_ovh_readback_test import B4OVHReadbackTests  # no-network OVH readback regression
from controller_b4_github_readonly_test import B4GitHubReadTests  # include B4 trusted GET tests in CI
from controller_b4_owner_evidence_binding_test import OwnerEvidenceTests  # B4 bounded owner/OVH proof tests
from controller_b4_registration_plan_test import B4RegistrationPlanTests  # register offline B4 planner regressions
from controller_b4_acceptance_contract_test import B4AcceptanceContractTests  # register B4 offline cases in existing CI suite
from controller_b2_retention_test import RetentionTests  # include B2 tests in registered offline CI suite
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


class B5aReadbackJoinTests(unittest.TestCase):
    def setUp(self):
        self.base = reconcile(RUN, JOBS, DOCUMENT, ARTIFACTS,
                              run_id=123, release_sha=SHA, expected_paths=[PATH])
        self.observation = {
            "schema": 1, "source": "separate_read_only_ovh_sftp_session",
            "release_id": "test-release", "release_sha": SHA, "run_id": 123,
            "status": "OBSERVED_MATCH", "writes_performed": False,
            "checkout_sha_verified": True,
            "files": [{"path": PATH, "expected_sha256": DIGEST,
                       "observed_sha256": DIGEST, "match": True}],
        }
        self.run = {"event": "workflow_dispatch", "status": "completed",
                    "conclusion": "success", "head_sha": SHA}

    def joined(self, observation=None, execution_run=None, base=None, main=None):
        return reconcile_independent_b4(
            self.base if base is None else base,
            self.observation if observation is None else observation,
            release_id="test-release", release_sha=SHA,
            run_id=123, expected_files=[PATH],
            execution_run=self.run if execution_run is None else execution_run,
            authorized_main_sha=main)

    def test_positive_offline_consistency_never_accepts_or_dispatches(self):
        x = self.joined(main=SHA)
        self.assertEqual(x["status"], "VERIFIED")
        self.assertEqual(x["evidence_scope"], "offline_consistency_only")
        self.assertFalse(x["independent_provenance_authenticated"])
        self.assertFalse(x["fully_accepted"])
        self.assertFalse(x["deployment_authorized"])
        self.assertFalse(x["dispatch_started"])
        self.assertEqual(x["browser_acceptance"], "PENDING")

    def test_main_authorization_change_fails_closed(self):
        x = self.joined(main="c" * 40)
        self.assertEqual(x["status"], "MISMATCH")
        self.assertEqual(x["reason"], "execution_commit_outside_authorization")

    def test_mismatch_or_missing_observation_never_verifies(self):
        bad = dict(self.observation)
        bad["files"] = [{**self.observation["files"][0],
                         "observed_sha256": "c" * 64, "match": False}]
        self.assertEqual(self.joined(observation=bad)["status"], "MISMATCH")
        self.assertEqual(self.joined(observation={})["status"], "MISMATCH")
        self.assertEqual(self.joined(observation=None)["status"], "VERIFIED")

    def test_missing_release_provenance_is_incomplete(self):
        self.assertEqual(self.joined(base={})["status"], "INCOMPLETE")

    def test_mismatched_release_identity_or_paths_fail_closed(self):
        self.assertEqual(self.joined(observation={**self.observation,
            "release_sha": "c" * 40})["reason"], "release_identity_mismatch")
        bad = {**self.observation, "files": [
            {**self.observation["files"][0], "path": "catalogus-public/kavel.php"}]}
        self.assertEqual(self.joined(observation=bad)["reason"],
                         "file_allowlist_mismatch")

    def test_not_a_successful_manual_b4_run(self):
        self.assertEqual(self.joined(execution_run={**self.run, "event": "push"})
                         ["status"], "INCOMPLETE")

    def test_incomplete_or_write_claim_cannot_verify(self):
        for key, value in (("writes_performed", True),
                           ("checkout_sha_verified", False),
                           ("source", "deployment_runner")):
            with self.subTest(key=key):
                self.assertEqual(self.joined(observation={
                    **self.observation, key: value})["status"], "INCOMPLETE")


if __name__ == "__main__":
    unittest.main()
