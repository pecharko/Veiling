"""Offline tests for run-bound evidence consistency, never production acceptance."""
import copy
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from release_b_artifact_contract import reconcile

SHA = "a" * 40
DIGEST = "b" * 64
OLD = "c" * 64
RUN = {"id": 123, "name": "Shared OVH manifest release with rollback",
       "event": "workflow_dispatch", "head_sha": SHA, "head_branch": "main",
       "status": "completed", "conclusion": "success"}
MANIFEST = {"schema": 1, "files": [
    {"path": "catalogus-public/assets/sample.css", "source_sha256": DIGEST, "remote_sha256": OLD}
]}
EVIDENCE = {"schema": 1, "release_id": "sample-release", "release_sha": SHA,
            "run_id": 123, "status": "VERIFIED", "rollback_artifact": None,
            "browser_acceptance": "PENDING", "files": [
                {"path": "catalogus-public/assets/sample.css", "source_sha256": DIGEST,
                 "baseline_sha256": OLD, "readback_sha256": DIGEST}
            ]}

class ReleaseBArtifactContractTest(unittest.TestCase):
    def test_matches_manifest_without_claiming_independent_acceptance(self):
        result = reconcile(RUN, EVIDENCE, MANIFEST, "sample-release")
        self.assertEqual(result["status"], "UPLOADER_ARTIFACT_CONTENT_CONSISTENT_ONLY")
        self.assertEqual(result["independent_ovh_readback"], "PENDING")
        self.assertEqual(result["browser_acceptance"], "PENDING")
        self.assertFalse(result["provenance_authenticated_by_caller"])
        self.assertEqual(result["release_administration"], "OPEN")

    def test_reject_run_mismatch(self):
        for key, value in (("id", 124), ("head_sha", "d" * 40),
                           ("event", "pull_request"), ("head_branch", "preview/ui"),
                           ("status", "in_progress"), ("conclusion", "failure")):
            with self.subTest(key=key), self.assertRaises(ValueError):
                reconcile({**RUN, key: value}, EVIDENCE, MANIFEST, "sample-release")

    def test_reject_content_and_release_mismatch(self):
        altered = copy.deepcopy(EVIDENCE)
        altered["files"][0]["source_sha256"] = "d" * 64
        with self.assertRaises(ValueError):
            reconcile(RUN, altered, MANIFEST, "sample-release")
        with self.assertRaises(ValueError):
            reconcile(RUN, EVIDENCE, MANIFEST, "wrong-release")
        altered = copy.deepcopy(EVIDENCE)
        altered["status"] = "ROLLED_BACK"
        with self.assertRaises(ValueError):
            reconcile(RUN, altered, MANIFEST, "sample-release")

    def test_reject_manifest_baseline_drift(self):
        altered = copy.deepcopy(MANIFEST)
        altered["files"][0]["remote_sha256"] = "e" * 64
        with self.assertRaises(ValueError):
            reconcile(RUN, EVIDENCE, altered, "sample-release")

    def test_supports_snapshot_v8_without_cross_release_promotions(self):
        run = {**RUN, "name": "Snapshot v8 catalog-only protected two-file release"}
        result = reconcile(run, EVIDENCE, MANIFEST, "sample-release")
        self.assertEqual(result["status"], "UPLOADER_ARTIFACT_CONTENT_CONSISTENT_ONLY")
        self.assertEqual(result["browser_acceptance"], "PENDING")

    def test_reject_duplicate_paths(self):
        altered = copy.deepcopy(MANIFEST)
        altered["files"].append(dict(altered["files"][0]))
        with self.assertRaises(ValueError):
            reconcile(RUN, EVIDENCE, altered, "sample-release")

if __name__ == "__main__":
    unittest.main()
