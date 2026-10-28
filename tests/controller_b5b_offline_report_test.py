"""B5b purely offline report tests: never access GitHub, SFTP or production."""
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
import controller_b5b_offline_report as module

SHA = "a" * 40
HASH = "b" * 64
PATH = "catalogus-public/index.php"


def payload():
    return {
        "base_report": {"provenance": {"github_actions": {
            "source": "github_api_run_jobs", "run_id": 123, "release_sha": SHA,
            "reason": "actions_success_not_independent_remote_proof"}}},
        "observation": {"schema": 1,
            "source": "separate_read_only_ovh_sftp_session",
            "release_id": "test-release", "release_sha": SHA, "run_id": 123,
            "status": "OBSERVED_MATCH", "writes_performed": False,
            "checkout_sha_verified": True,
            "files": [{"path": PATH, "expected_sha256": HASH,
                       "observed_sha256": HASH, "match": True}]},
        "execution_run": {"event": "workflow_dispatch", "conclusion": "success",
                          "status": "completed", "head_sha": SHA},
        "release_id": "test-release", "release_sha": SHA,
        "release_run_id": 123, "expected_paths": [PATH],
        "authorized_main_sha": SHA,
    }


class B5bOfflineReportTests(unittest.TestCase):
    def test_consistent_snapshot_never_grants_acceptance(self):
        r = module.render(payload())
        self.assertEqual(r["status"], "VERIFIED")
        self.assertEqual(r["evidence_scope"], "offline_consistency_only")
        self.assertFalse(r["independent_provenance_authenticated"])
        self.assertFalse(r["fully_accepted"])
        self.assertFalse(r["deployment_authorized"])
        self.assertFalse(r["dispatch_started"])
        self.assertEqual(r["browser_acceptance"], "PENDING")

    def test_wrong_main_commit_is_mismatch(self):
        p = payload()
        p["authorized_main_sha"] = "c" * 40
        self.assertEqual(module.render(p)["reason"],
                         "execution_commit_outside_authorization")

    def test_missing_evidence_incomplete(self):
        p = payload()
        p["observation"] = None
        self.assertEqual(module.render(p)["status"], "INCOMPLETE")

    def test_wrong_hash_mismatch(self):
        p = payload()
        p["observation"]["files"][0]["observed_sha256"] = "c" * 64
        self.assertEqual(module.render(p)["status"], "MISMATCH")

    def test_extra_fields_fail_closed(self):
        p = payload()
        p["override_acceptance"] = True
        with self.assertRaises(ValueError):
            module.render(p)

    def test_local_cli_writes_bounded_json_without_network(self):
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / "input.json"
            target = Path(directory) / "report.json"
            source.write_text(json.dumps(payload()), encoding="utf-8")
            with patch.object(sys, "argv", ["report", "--input", str(source),
                                            "--output", str(target)]):
                self.assertEqual(module.main(), 0)
            self.assertEqual(json.loads(target.read_text())["status"], "VERIFIED")


class B5fFullReportTests(unittest.TestCase):
    def full(self):
        p = payload()
        p["b4_run"] = {"id": 45, "head_sha": SHA, "head_branch": "main",
                       "path": ".github/workflows/b4-ovh-verification.yml",
                       "event": "workflow_dispatch", "status": "completed",
                       "conclusion": "success"}
        p["b4_jobs"] = [{"name": "readback", "head_sha": SHA,
                         "status": "completed", "conclusion": "success"}]
        p["b4_run_id"] = 45
        p["b4_execution_sha"] = SHA
        return p

    def test_full_evidence_report_is_not_acceptance(self):
        p = self.full()
        result = module.render(p)
        self.assertEqual(result["status"], "VERIFIED")
        self.assertEqual(result["evidence_scope"], "offline_consistency_only")
        self.assertIn("b4_run_identity", result)
        self.assertFalse(result["independent_provenance_authenticated"])
        self.assertFalse(result["fully_accepted"])
        self.assertFalse(result["deployment_authorized"])
        self.assertFalse(result["dispatch_started"])
        self.assertEqual(result["browser_acceptance"], "PENDING")

    def test_bad_b4_job_makes_incomplete(self):
        p = self.full()
        p["b4_jobs"] = []
        self.assertEqual(module.render(p)["status"], "INCOMPLETE")

    def test_b4_commit_drift_fails_closed(self):
        p = self.full()
        p["b4_execution_sha"] = "c" * 40
        self.assertEqual(module.render(p)["status"], "MISMATCH")

    def test_partial_extended_snapshot_rejected(self):
        p = payload()
        p["b4_run_id"] = 45
        with self.assertRaises(ValueError):
            module.render(p)

    def test_cli_accepts_full_offline_evidence(self):
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / "input.json"
            target = Path(directory) / "report.json"
            source.write_text(json.dumps(self.full()), encoding="utf-8")
            with patch.object(sys, "argv", ["report", "--input", str(source),
                                            "--output", str(target)]):
                self.assertEqual(module.main(), 0)
            self.assertEqual(json.loads(target.read_text())["status"], "VERIFIED")



if __name__ == "__main__":
    unittest.main()
