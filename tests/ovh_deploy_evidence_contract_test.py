import unittest
from scripts.ovh_deploy_evidence_contract import validate

H = "a" * 64
BASE = {
    "schema": 1, "release_id": "test-release", "release_sha": "b" * 40,
    "run_id": 123, "status": "VERIFIED", "rollback_artifact": 45,
    "browser_acceptance": "PENDING",
    "files": [{"path": "catalogus-public/admin/admin.css", "source_sha256": H,
               "baseline_sha256": "ABSENT", "readback_sha256": H}]
}


class EvidenceContractTests(unittest.TestCase):
    def test_verified(self):
        self.assertEqual(validate(BASE)["status"], "VERIFIED")

    def test_readback_mismatch(self):
        d = {**BASE, "files": [{**BASE["files"][0], "readback_sha256": "c"*64}]}
        with self.assertRaises(ValueError):
            validate(d)

    def test_failed_can_have_no_readback(self):
        d = {**BASE, "status": "FAILED", "files": [{**BASE["files"][0], "readback_sha256": None}]}
        self.assertEqual(validate(d)["status"], "FAILED")

    def test_no_accepted_failed(self):
        d = {**BASE, "status": "FAILED", "browser_acceptance": "ACCEPTED"}
        with self.assertRaises(ValueError):
            validate(d)

    def test_no_duplicate_paths(self):
        d = {**BASE, "files": BASE["files"] * 2}
        with self.assertRaises(ValueError):
            validate(d)

    def test_reject_missing_evidence(self):
        d = {k: v for k, v in BASE.items() if k != "rollback_artifact"}
        with self.assertRaises(ValueError):
            validate(d)


if __name__ == "__main__":
    unittest.main()
