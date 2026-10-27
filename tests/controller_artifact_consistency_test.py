#!/usr/bin/env python3
"""Offline negative contract tests, no GitHub or OVH network."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_artifact_consistency import inspect_release_evidence as inspect

SHA = "a" * 40
DIGEST = "b" * 64
PATH = "catalogus-public/index.php"
DOC = {"schema": 1, "release_id": "test-release", "release_sha": SHA,
       "run_id": 123, "status": "VERIFIED", "files": [{
           "path": PATH, "source_sha256": DIGEST, "baseline_sha256": "ABSENT",
           "readback_sha256": DIGEST}], "rollback_artifact": 502,
       "browser_acceptance": "PENDING"}
ARTIFACTS = [
    {"name": "shared-ovh-evidence-123", "id": 501, "expired": False,
     "workflow_run": {"id": 123}},
    {"name": "shared-ovh-rollback-123", "id": 502, "expired": False,
     "workflow_run": {"id": 123}},
]


class ConsistencyTest(unittest.TestCase):
    def check(self, doc=DOC, inventory=ARTIFACTS, paths=None):
        return inspect(doc, inventory, run_id=123, release_sha=SHA,
                       expected_paths=[PATH] if paths is None else paths)

    def test_valid_metadata_is_not_remote_proof(self):
        result = self.check()
        self.assertEqual(result["status"], "INCOMPLETE")
        self.assertEqual(result["reason"], "consistent_metadata_not_remote_proof")

    def test_missing_evidence(self):
        self.assertEqual(self.check(None)["reason"], "missing_evidence_document")

    def test_mismatched_run(self):
        self.assertEqual(self.check({**DOC, "run_id": 124})["status"], "FAILED")

    def test_mismatched_sha(self):
        self.assertEqual(self.check({**DOC, "release_sha": "c" * 40})["status"], "FAILED")

    def test_mismatched_files(self):
        self.assertEqual(self.check(paths=["catalogus-public/other.php"])["status"], "FAILED")

    def test_mismatched_readback(self):
        doc = {**DOC, "files": [{**DOC["files"][0], "readback_sha256": "c" * 64}]}
        self.assertEqual(self.check(doc)["reason"], "invalid_evidence_contract")

    def test_missing_duplicate_expired_artifact(self):
        for inventory in ([], ARTIFACTS + [ARTIFACTS[0]],
                          [{**ARTIFACTS[0], "expired": True}, ARTIFACTS[1]]):
            with self.subTest(inventory=inventory):
                self.assertEqual(self.check(inventory=inventory)["status"], "INCOMPLETE")

    def test_malformed_unrelated_inventory_entry_is_ignored(self):
        self.assertEqual(self.check(inventory=[None] + ARTIFACTS)["status"],
                         "INCOMPLETE")

    def test_artifact_not_bound_to_run(self):
        inventory = [{**ARTIFACTS[0], "workflow_run": {"id": 124}}, ARTIFACTS[1]]
        self.assertEqual(self.check(inventory=inventory)["reason"],
                         "expired_or_unbound_artifact")

    def test_wrong_rollback_artifact_id(self):
        self.assertEqual(self.check({**DOC, "rollback_artifact": 999})["status"],
                         "FAILED")

    def test_failed_deployment_not_verified(self):
        self.assertEqual(self.check({**DOC, "status": "FAILED"})["reason"],
                         "release_not_verified")

    def test_disallow_ambiguous_allowlist(self):
        with self.assertRaises(ValueError):
            self.check(paths=[PATH, PATH])


if __name__ == "__main__":
    unittest.main()
