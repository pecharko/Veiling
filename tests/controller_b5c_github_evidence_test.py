"""B5c regressions: injection-only, no GitHub calls or network."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_b5c_github_evidence import collect_and_render, validate_b4_run_identity, unify_offline_b5_evidence, collect_b4_github_metadata, collect_full_b5_report

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



class B5eUnifiedEvidenceTests(unittest.TestCase):
    def setUp(self):
        self.base = {"provenance": {"github_actions": {
            "source": "github_api_run_jobs", "run_id": 12,
            "release_sha": SHA,
            "reason": "actions_success_not_independent_remote_proof"}}}
        self.observation = {
            "schema": 1, "source": "separate_read_only_ovh_sftp_session",
            "release_id": "demo", "release_sha": SHA, "run_id": 12,
            "status": "OBSERVED_MATCH", "writes_performed": False,
            "checkout_sha_verified": True,
            "files": [{"path": PATH, "expected_sha256": DIGEST,
                       "observed_sha256": DIGEST, "match": True}]}
        self.run = {"id": 45, "head_sha": SHA, "head_branch": "main",
                    "path": ".github/workflows/b4-ovh-verification.yml",
                    "event": "workflow_dispatch", "status": "completed",
                    "conclusion": "success"}
        self.jobs = [{"name": "readback", "head_sha": SHA,
                      "status": "completed", "conclusion": "success"}]

    def check(self, **changes):
        data = dict(base_report=self.base, observation=self.observation,
                    b4_run=self.run, b4_jobs=self.jobs,
                    release_id="demo", release_sha=SHA,
                    release_run_id=12, expected_paths=[PATH],
                    authorized_main_sha=SHA,
                    b4_run_id=45, b4_execution_sha=SHA)
        data.update(changes)
        return unify_offline_b5_evidence(**data)

    def test_consistent_both_is_not_independent_acceptance(self):
        result = self.check()
        self.assertEqual(result["status"], "VERIFIED")
        self.assertFalse(result["independent_provenance_authenticated"])
        self.assertFalse(result["fully_accepted"])
        self.assertFalse(result["deployment_authorized"])
        self.assertFalse(result["dispatch_started"])
        self.assertEqual(result["browser_acceptance"], "PENDING")

    def test_mismatched_authorized_main_overrides_green_b4(self):
        r = self.check(authorized_main_sha="c" * 40)
        self.assertEqual(r["status"], "MISMATCH")

    def test_invalid_b4_identity_overrides_matching_hashes(self):
        r = self.check(b4_run={**self.run, "id": 46})
        self.assertEqual(r["status"], "MISMATCH")

    def test_missing_b4_job_is_incomplete(self):
        self.assertEqual(self.check(b4_jobs=[])["status"], "INCOMPLETE")

    def test_missing_observation_is_incomplete(self):
        self.assertEqual(self.check(observation=None)["status"], "INCOMPLETE")

    def test_hash_drift_is_mismatch(self):
        obs = {**self.observation, "files": [
            {**self.observation["files"][0], "observed_sha256": "c" * 64}]}
        self.assertEqual(self.check(observation=obs)["status"], "MISMATCH")



class B5gGitHubMetadataTests(unittest.TestCase):
    def setUp(self):
        self.paths = []
        self.run = {"id": 42, "path": ".github/workflows/b4-ovh-verification.yml"}
        self.jobs = [{"name": "readback", "status": "completed"}]

    def reader(self, path, token):
        self.paths.append((path, token))
        return self.run if path == "/actions/runs/42" else {
            "total_count": 1, "jobs": self.jobs}

    def test_only_two_bounded_read_requests(self):
        run, jobs = collect_b4_github_metadata(
            42, "read-only-token", read_json=self.reader)
        self.assertEqual(run, self.run)
        self.assertEqual(jobs, self.jobs)
        self.assertEqual(self.paths, [
            ("/actions/runs/42", "read-only-token"),
            ("/actions/runs/42/jobs?per_page=100", "read-only-token")])

    def test_invalid_run_or_missing_token_never_issues_get(self):
        for run_id, token in ((0, "token"), (42, ""), (True, "token")):
            with self.subTest(run_id=run_id, token=token):
                with self.assertRaises(ValueError):
                    collect_b4_github_metadata(
                        run_id, token, read_json=self.reader)
        self.assertEqual(self.paths, [])

    def test_partial_or_paginated_jobs_fail_closed(self):
        def missing(path, token):
            return self.run if path == "/actions/runs/42" else {
                "total_count": 2, "jobs": self.jobs}
        with self.assertRaisesRegex(ValueError, "incomplete or unbounded"):
            collect_b4_github_metadata(42, "token", read_json=missing)

    def test_no_readback_artifact_is_inferred(self):
        run, jobs = collect_b4_github_metadata(
            42, "token", read_json=self.reader)
        self.assertNotIn("observation", run)
        self.assertNotIn("independent_ovh_readback", run)
        self.assertEqual(len(jobs), 1)


class B5hSingleReportTests(unittest.TestCase):
    def setUp(self):
        self.calls = []
        self.base = {"provenance": {"github_actions": {
            "source": "github_api_run_jobs", "run_id": 12, "release_sha": SHA,
            "reason": "actions_success_not_independent_remote_proof"}}}
        self.obs = {"schema": 1,
            "source": "separate_read_only_ovh_sftp_session",
            "release_id": "demo", "release_sha": SHA, "run_id": 12,
            "status": "OBSERVED_MATCH", "writes_performed": False,
            "checkout_sha_verified": True,
            "files": [{"path": PATH, "expected_sha256": DIGEST,
                       "observed_sha256": DIGEST, "match": True}]}
        self.b4run = {"id": 45, "head_sha": SHA, "head_branch": "main",
                      "path": ".github/workflows/b4-ovh-verification.yml",
                      "event": "workflow_dispatch", "status": "completed",
                      "conclusion": "success"}
        self.b4jobs = [{"name": "readback", "head_sha": SHA,
                        "status": "completed", "conclusion": "success"}]

    def release_collect(self, run_id, sha, paths, token):
        self.calls.append(("release", run_id, sha, paths, token))
        return self.base

    def b4_collect(self, run_id, token):
        self.calls.append(("b4", run_id, token))
        return self.b4run, self.b4jobs

    def check(self, **overrides):
        params = dict(release_id="demo", release_sha=SHA, release_run_id=12,
                      expected_paths=[PATH], authorized_main_sha=SHA,
                      observation=self.obs, b4_run_id=45,
                      b4_execution_sha=SHA, token="read-token",
                      release_collector=self.release_collect,
                      b4_collector=self.b4_collect)
        params.update(overrides)
        return collect_full_b5_report(**params)

    def test_single_bounded_readonly_sequence_no_acceptance(self):
        report = self.check()
        self.assertEqual(report["status"], "VERIFIED")
        self.assertEqual([c[0] for c in self.calls], ["release", "b4"])
        self.assertFalse(report["fully_accepted"])
        self.assertFalse(report["independent_provenance_authenticated"])
        self.assertFalse(report["deployment_authorized"])
        self.assertFalse(report["dispatch_started"])
        self.assertEqual(report["browser_acceptance"], "PENDING")

    def test_bad_pin_rejected_before_network(self):
        with self.assertRaises(ValueError):
            self.check(authorized_main_sha="bad")
        self.assertEqual(self.calls, [])

    def test_b4_commit_drift_fails_closed(self):
        self.assertEqual(self.check(b4_execution_sha="c" * 40)["status"], "MISMATCH")

    def test_missing_remote_observation_never_verifies(self):
        self.assertEqual(self.check(observation=None)["status"], "INCOMPLETE")

    def test_collector_failure_not_hidden(self):
        def fail(*args):
            raise ValueError("incomplete evidence")
        with self.assertRaisesRegex(ValueError, "incomplete evidence"):
            self.check(release_collector=fail)


if __name__ == "__main__":
    unittest.main()
