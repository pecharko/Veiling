#!/usr/bin/env python3
"""Offline Phase A evidence fetch tests; no network or production access."""
import io
import json
import sys
import unittest
import zipfile
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_evidence_collector import collect, BASE
from controller_run_evidence_binding import REQUIRED_STEPS, WORKFLOW

SHA = "a" * 40
DIGEST = "b" * 64
PATH = "catalogus-public/index.php"
RUN = {"id": 123, "head_sha": SHA, "path": WORKFLOW, "event": "workflow_dispatch",
       "head_branch": "main", "status": "completed", "conclusion": "success"}
JOB = {"name": "release", "status": "completed", "conclusion": "success",
       "steps": [{"name": s, "status": "completed", "conclusion": "success"} for s in REQUIRED_STEPS]}
DOCUMENT = {"schema": 1, "release_id": "test-release", "release_sha": SHA,
            "run_id": 123, "status": "VERIFIED", "files": [{
                "path": PATH, "source_sha256": DIGEST, "baseline_sha256": "ABSENT",
                "readback_sha256": DIGEST}], "rollback_artifact": 502,
            "browser_acceptance": "PENDING"}
ARTIFACTS = [
    {"id": 501, "name": "shared-ovh-evidence-123", "expired": False, "workflow_run": {"id": 123}},
    {"id": 502, "name": "shared-ovh-rollback-123", "expired": False, "workflow_run": {"id": 123}},
]


def zipped(value=DOCUMENT, name="deployment-evidence.json"):
    out = io.BytesIO()
    with zipfile.ZipFile(out, "w") as archive:
        archive.writestr(name, json.dumps(value))
    return out.getvalue()


class CollectorTests(unittest.TestCase):
    def execute(self, *, run=RUN, jobs=None, artifacts=None, blob=None, job_count=1):
        hits = []
        def read_json(path, token):
            hits.append(path)
            if path.endswith("/jobs?per_page=100"):
                return {"total_count": job_count, "jobs": [JOB] if jobs is None else jobs}
            if path.endswith("/artifacts?per_page=100"):
                return {"total_count": 2, "artifacts": ARTIFACTS if artifacts is None else artifacts}
            return run

        def read_bytes(url, token, *, limit):
            self.assertEqual(url, BASE + "/actions/artifacts/501/zip")
            self.assertLessEqual(limit, 8 * 1024 * 1024)
            return zipped() if blob is None else blob

        return collect(123, SHA, [PATH], "fake-token", read_json=read_json,
                       read_bytes=read_bytes), hits

    def test_valid_metadata_never_authenticates_production(self):
        result, hits = self.execute()
        self.assertFalse(result["fully_accepted"])
        self.assertEqual(result["review"]["independent_remote_readback"], "NOT_VERIFIED")
        self.assertEqual(len(hits), 3)

    def test_wrong_run_sha_fails_closed(self):
        result, _ = self.execute(run={**RUN, "head_sha": "c" * 40})
        self.assertEqual(result["review"]["run_metadata"]["status"], "FAILED")

    def test_missing_evidence(self):
        result, _ = self.execute(artifacts=[ARTIFACTS[1]])
        self.assertEqual(result["review"]["artifact_metadata"]["status"], "INCOMPLETE")

    def test_invalid_archive_member(self):
        with self.assertRaises(ValueError):
            self.execute(blob=zipped(name="../unsafe.json"))

    def test_incomplete_pagination(self):
        with self.assertRaises(ValueError):
            self.execute(job_count=101)

    def test_invalid_input_prevents_any_fetch(self):
        with self.assertRaises(ValueError):
            collect(123, "wrong", [PATH], "fake-token",
                    read_json=lambda *a: self.fail("network must not be contacted"))

    def test_failed_release(self):
        result, _ = self.execute(jobs=[{**JOB, "conclusion": "failure"}])
        self.assertEqual(result["review"]["run_metadata"]["status"], "FAILED")


if __name__ == "__main__":
    unittest.main()
