"""B4 read-only OVH observation tests; never initiate network."""
import sys
import unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
import controller_b4_ovh_readback as module

SHA = "a" * 40
DATA = b"checked content"
MANIFEST = {"schema": 1, "files": [
    {"path": "catalogus-public/admin/admin.css",
     "source_sha256": module.digest(DATA), "remote_sha256": "ABSENT"}]}


class B4OVHReadbackTests(unittest.TestCase):
    def test_observation_is_not_acceptance(self):
        with patch.object(module, "load_manifest", return_value=(MANIFEST, {}, {})):
            result = module.verify("review-demo", SHA, 31, read=lambda _: DATA, checkout_check=lambda: SHA)
        self.assertEqual(result["status"], "OBSERVED_MATCH")
        self.assertFalse(result["writes_performed"])
        self.assertFalse(result["independent_execution_authenticated"])
        self.assertEqual(result["browser_acceptance"], "PENDING")

    def test_mismatch_fails(self):
        with patch.object(module, "load_manifest", return_value=(MANIFEST, {}, {})):
            for raw in (b"different", None):
                with self.subTest(raw=raw):
                    result = module.verify("review-demo", SHA, 31, read=lambda _: raw, checkout_check=lambda: SHA)
                    self.assertEqual(result["status"], "MISMATCH")

    def test_invalid_identity_skips_manifest(self):
        for sha, run in (("bad", 31), (SHA, 0), (SHA, True)):
            with patch.object(module, "load_manifest",
                              side_effect=AssertionError("manifest accessed")):
                with self.assertRaises(ValueError):
                    module.verify("review-demo", sha, run, read=lambda _: DATA, checkout_check=lambda: SHA)

    def test_wrong_checkout_blocks_before_manifest_or_ovh(self):
        with patch.object(module, "load_manifest", side_effect=AssertionError("manifest accessed")):
            with self.assertRaisesRegex(ValueError, "unpinned"):
                module.verify("review-demo", SHA, 31,
                              read=lambda _: self.fail("remote read started"),
                              checkout_check=lambda: "b" * 40)

    def test_checkout_requires_clean_tree(self):
        from types import SimpleNamespace
        with patch.object(module.subprocess, "run", side_effect=[
            SimpleNamespace(stdout=SHA + "\\n"),
            SimpleNamespace(stdout=" M releases/demo.json\\n")
        ]):
            with self.assertRaisesRegex(ValueError, "clean pinned"):
                module.check_checkout()


    def test_github_run_preflight_binds_exact_release(self):
        from controller_run_evidence_binding import REQUIRED_STEPS, WORKFLOW
        run = {"id": 31, "head_sha": SHA, "path": WORKFLOW,
               "event": "workflow_dispatch", "head_branch": "main",
               "status": "completed", "conclusion": "success"}
        jobs = [{"name": "release", "status": "completed",
                 "conclusion": "success", "steps": [
                     {"name": n, "status": "completed", "conclusion": "success"}
                     for n in REQUIRED_STEPS]}]
        seen = []
        def read(path, token):
            seen.append(path)
            return run if path == "/actions/runs/31" else {"total_count": 1, "jobs": jobs}
        result = module.verify_release_run(31, SHA, "read-token", read_json=read)
        self.assertEqual(result["reason"], "actions_success_not_independent_remote_proof")
        self.assertEqual(seen, ["/actions/runs/31", "/actions/runs/31/jobs?per_page=100"])
        for bad_run in ({**run, "head_sha": "b" * 40},
                        {**run, "event": "pull_request"},
                        {**run, "conclusion": "failure"}):
            with self.subTest(bad_run=bad_run):
                with self.assertRaises(ValueError):
                    module.verify_release_run(31, SHA, "read-token",
                        read_json=lambda path, token: bad_run if path == "/actions/runs/31" else {"total_count": 1, "jobs": jobs})
        with self.assertRaises(ValueError):
            module.verify_release_run(31, SHA, "read-token",
                read_json=lambda path, token: run if path == "/actions/runs/31" else {"total_count": 100, "jobs": jobs})

    def test_missing_token_fails_before_api(self):
        with self.assertRaises(ValueError):
            module.verify_release_run(31, SHA, "", read_json=lambda *a: self.fail("network"))

    def test_reader_failure_does_not_report_success(self):
        with patch.object(module, "load_manifest", return_value=(MANIFEST, {}, {})):
            with self.assertRaises(OSError):
                module.verify("review-demo", SHA, 31,
                              read=lambda _: (_ for _ in ()).throw(OSError("remote failed")), checkout_check=lambda: SHA)


if __name__ == "__main__":
    unittest.main()
