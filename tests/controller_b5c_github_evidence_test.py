"""B5c regressions: injection-only, no GitHub calls or network."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_b5c_github_evidence import collect_and_render, validate_b4_run_identity

SHA = "a" * 40
DIGEST = "b" * 64
PATH = "catalogus-public/index.php"


class B5cTests(unittest.TestCase):
    def make(self):
        seen = []
        def collector(rid, sha, paths, token):
            seen.append((rid, sha, paths, token))
            return {"provenance": {"github_actions": {
                "source": "github_api_run_jobs", "run_id": 12,
                "release_sha": SHA, "reason":
                "actions_success_not_independent_remote_proof"}}}
        obs = {"schema": 1, "source": "separate_read_only_ovh_sftp_session",
               "release_id": "demo", "release_sha": SHA, "run_id": 12,
               "status": "OBSERVED_MATCH", "writes_performed": False,
               "checkout_sha_verified": True,
               "files": [{"path": PATH, "expected_sha256": DIGEST,
                          "observed_sha256": DIGEST, "match": True}]}
        run = {"event": "workflow_dispatch", "status": "completed",
               "conclusion": "success", "head_sha": SHA}
        args = dict(release_id="demo", release_sha=SHA, release_run_id=12,
                    expected_paths=[PATH], authorized_main_sha=SHA,
                    observation=obs, execution_run=run, token="read-token",
                    collector=collector)
        return args, seen

    def test_valid_bound_evidence_only_consistency(self):
        args, seen = self.make()
        result = collect_and_render(**args)
        self.assertEqual(result["status"], "VERIFIED")
        self.assertEqual(seen, [(12, SHA, [PATH], "read-token")])
        self.assertFalse(result["independent_provenance_authenticated"])
        self.assertFalse(result["fully_accepted"])
        self.assertFalse(result["deployment_authorized"])
        self.assertFalse(result["dispatch_started"])

    def test_absent_token_prevents_collection(self):
        args, seen = self.make()
        args["token"] = ""
        with self.assertRaises(ValueError):
            collect_and_render(**args)
        self.assertEqual(seen, [])

    def test_authorized_main_drift_blocks(self):
        args, _ = self.make()
        args["authorized_main_sha"] = "c" * 40
        result = collect_and_render(**args)
        self.assertEqual(result["status"], "MISMATCH")
        self.assertEqual(result["reason"], "execution_commit_outside_authorization")

    def test_missing_readback_never_accepted(self):
        args, _ = self.make()
        args["observation"] = None
        self.assertEqual(collect_and_render(**args)["status"], "INCOMPLETE")

    def test_collector_error_fails_closed(self):
        args, _ = self.make()
        def broken(*unused):
            raise ValueError("Invalid GitHub provenance")
        args["collector"] = broken
        with self.assertRaisesRegex(ValueError, "Invalid GitHub provenance"):
            collect_and_render(**args)


class B5dRunIdentityTests(unittest.TestCase):
    def setUp(self):
        self.run = {"id": 45, "head_sha": SHA, "head_branch": "main",
                    "path": ".github/workflows/b4-ovh-verification.yml",
                    "event": "workflow_dispatch", "status": "completed",
                    "conclusion": "success"}
        self.jobs = [{"name": "readback", "head_sha": SHA,
                      "status": "completed", "conclusion": "success"}]

    def check(self, run=None, jobs=None, sha=SHA):
        return validate_b4_run_identity(
            self.run if run is None else run,
            self.jobs if jobs is None else jobs,
            expected_b4_run_id=45, expected_main_sha=sha)

    def test_success_never_authenticates_independent_provenance(self):
        result = self.check()
        self.assertEqual(result["status"], "VERIFIED")
        self.assertFalse(result["independent_provenance_authenticated"])

    def test_parallel_main_drift_is_mismatch(self):
        self.assertEqual(self.check(sha="c" * 40)["status"], "MISMATCH")

    def test_wrong_run_id_is_mismatch(self):
        self.assertEqual(self.check(run={**self.run, "id": 46})["status"], "MISMATCH")

    def test_missing_duplicate_or_failed_job_never_verifies(self):
        for jobs in ([], self.jobs * 2,
                     [{**self.jobs[0], "conclusion": "failure"}],
                     [{**self.jobs[0], "head_sha": "c" * 40}]):
            with self.subTest(jobs=jobs):
                self.assertEqual(self.check(jobs=jobs)["status"], "INCOMPLETE")

    def test_push_or_wrong_workflow_never_verifies(self):
        for field, value in (("event", "push"),
                             ("path", ".github/workflows/shared-ovh-release.yml"),
                             ("conclusion", "failure")):
            with self.subTest(field=field):
                self.assertEqual(self.check(run={**self.run, field: value})
                                 ["status"], "INCOMPLETE")



if __name__ == "__main__":
    unittest.main()
