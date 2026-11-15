"""Fail-closed external evidence correlation tests; no source authentication."""
import copy
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from release_b_independent_contract import reconcile

SHA = "a" * 40
NEW = "b" * 64
OLD = "c" * 64
UPLOAD = {
    "schema": 1, "release_id": "example-release", "release_sha": SHA,
    "run_id": 123, "status": "VERIFIED", "rollback_artifact": None,
    "browser_acceptance": "PENDING",
    "files": [{"path": "catalogus-public/example.css", "source_sha256": NEW,
               "baseline_sha256": OLD, "readback_sha256": NEW}]
}
READBACK = {
    "schema": 1, "release_id": "example-release", "release_sha": SHA,
    "run_id": 123, "origin": "separate-read-only-sftp-collector",
    "collection_id": "collection-20261115-01", "status": "COLLECTED",
    "files": [{"path": "catalogus-public/example.css", "remote_sha256": NEW}]
}
BROWSER = {
    "schema": 1, "release_id": "example-release", "release_sha": SHA,
    "run_id": 123, "origin": "trusted-external-browser",
    "session_id": "browser-session-01", "status": "PASS",
    "checks": ["public-catalog", "artist-gallery"]
}

class ExternalEvidenceTest(unittest.TestCase):
    def test_matching_claims_still_never_close_release(self):
        result = reconcile(UPLOAD, READBACK, BROWSER)
        self.assertEqual(result["status"], "REVIEW_EXTERNAL_ORIGINS")
        self.assertEqual(result["source_authenticity"], "NOT_ESTABLISHED_BY_THIS_CONTRACT")
        self.assertFalse(result["independent_origin_authenticated"])
        self.assertFalse(result["browser_origin_authenticated"])
        self.assertEqual(result["release_administration"], "OPEN")

    def test_fail_is_not_promoted(self):
        result = reconcile(UPLOAD, READBACK, {**BROWSER, "status": "FAIL"})
        self.assertEqual(result["status"], "BROWSER_REJECTED")
        self.assertEqual(result["release_administration"], "OPEN")

    def test_reject_broken_release_binding(self):
        for key, changed in (("release_sha", "d"*40), ("run_id", 124),
                             ("release_id", "other-release")):
            with self.subTest(key=key), self.assertRaises(ValueError):
                reconcile(UPLOAD, {**READBACK, key: changed}, BROWSER)
            with self.subTest(key="browser-"+key), self.assertRaises(ValueError):
                reconcile(UPLOAD, READBACK, {**BROWSER, key: changed})

    def test_reject_mismatched_remote_hash(self):
        bad = copy.deepcopy(READBACK)
        bad["files"][0]["remote_sha256"] = OLD
        with self.assertRaises(ValueError):
            reconcile(UPLOAD, bad, BROWSER)

    def test_reject_mismatched_or_additional_paths(self):
        for path in ("catalogus-public/other.css", "private/../unsafe.php"):
            bad = copy.deepcopy(READBACK)
            bad["files"][0]["path"] = path
            with self.subTest(path=path), self.assertRaises(ValueError):
                reconcile(UPLOAD, bad, BROWSER)
        bad = copy.deepcopy(READBACK)
        bad["files"].append(dict(bad["files"][0]))
        with self.assertRaises(ValueError):
            reconcile(UPLOAD, bad, BROWSER)

    def test_reject_uploader_log_as_independent_origin(self):
        with self.assertRaises(ValueError):
            reconcile(UPLOAD, {**READBACK, "origin": "uploader-log"}, BROWSER)
        with self.assertRaises(ValueError):
            reconcile(UPLOAD, {**READBACK, "status": "FAILED"}, BROWSER)

    def test_reject_browser_claim_missing_checks(self):
        for checks in ([], ["public-catalog", "public-catalog"], ["../unsafe"]):
            with self.subTest(checks=checks), self.assertRaises(ValueError):
                reconcile(UPLOAD, READBACK, {**BROWSER, "checks": checks})
        with self.assertRaises(ValueError):
            reconcile(UPLOAD, READBACK, {**BROWSER, "origin": "github-runner"})

    def test_reject_forged_schema_and_unverified_deployment(self):
        with self.assertRaises(ValueError):
            reconcile({**UPLOAD, "status": "FAILED"}, READBACK, BROWSER)
        with self.assertRaises(ValueError):
            reconcile(UPLOAD, {**READBACK, "schema": 2}, BROWSER)
        with self.assertRaises(ValueError):
            reconcile(UPLOAD, READBACK, {**BROWSER, "schema": 2})

if __name__ == "__main__":
    unittest.main()
