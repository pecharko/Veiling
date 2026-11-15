"""Offline bounded artifact ZIP and run provenance regression tests."""
import io
import json
import sys
import unittest
import zipfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from release_b_zip_review import evidence_artifact, read_evidence_zip

RUN = {"id": 123, "name": "Shared OVH manifest release with rollback",
       "path": ".github/workflows/shared-ovh-release.yml",
       "repository": {"full_name": "pecharko/Veiling"},
       "event": "workflow_dispatch", "head_branch": "main",
       "head_sha": "a" * 40, "status": "completed", "conclusion": "success"}
ARTIFACT = {"id": 456, "name": "shared-ovh-evidence-123", "expired": False,
            "size_in_bytes": 280, "workflow_run": {"id": 123}}
EVIDENCE = {"schema": 1, "release_id": "test-release", "release_sha": "a" * 40,
            "run_id": 123, "status": "VERIFIED", "rollback_artifact": None,
            "browser_acceptance": "PENDING", "files": [
                {"path": "catalogus-public/a.css", "source_sha256": "b" * 64,
                 "baseline_sha256": "c" * 64, "readback_sha256": "b" * 64}]}


def zip_data(files):
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", compression=zipfile.ZIP_DEFLATED) as z:
        for name, content in files:
            z.writestr(name, content)
    return buf.getvalue()


class ZipReviewTest(unittest.TestCase):
    def test_exact_artifact_bound_to_run(self):
        self.assertEqual(evidence_artifact(RUN, {"artifacts": [ARTIFACT]}), 456)

    def test_unsafe_or_ambiguous_metadata_rejected(self):
        for changed in ({"expired": True}, {"id": 0}, {"size_in_bytes": 9999999},
                        {"workflow_run": {"id": 124}}):
            with self.subTest(changed=changed), self.assertRaises(ValueError):
                evidence_artifact(RUN, {"artifacts": [{**ARTIFACT, **changed}]})
        with self.assertRaises(ValueError):
            evidence_artifact(RUN, {"artifacts": [ARTIFACT, ARTIFACT]})
        with self.assertRaises(ValueError):
            evidence_artifact({**RUN, "repository": {"full_name": "other/repo"}},
                              {"artifacts": [ARTIFACT]})
        with self.assertRaises(ValueError):
            evidence_artifact({**RUN, "path": ".github/workflows/fake.yml"},
                              {"artifacts": [ARTIFACT]})

    def test_snapshot_v8_artifact_name_isolated(self):
        run = {**RUN, "name": "Snapshot v8 catalog-only protected two-file release",
               "path": ".github/workflows/snapshot-v8-protected-release.yml"}
        with self.assertRaises(ValueError):
            evidence_artifact(run, {"artifacts": [ARTIFACT]})
        self.assertEqual(evidence_artifact(run, {"artifacts": [
            {**ARTIFACT, "name": "snapshot-v8-evidence-123"}]}), 456)

    def test_one_exact_member_json(self):
        data = zip_data([("deployment-evidence.json", json.dumps(EVIDENCE))])
        self.assertEqual(read_evidence_zip(data)["release_id"], "test-release")

    def test_reject_traversal_duplicate_other_and_missing_files(self):
        for files in (
            [("../deployment-evidence.json", json.dumps(EVIDENCE))],
            [("folder/deployment-evidence.json", json.dumps(EVIDENCE))],
            [("deployment-evidence.json", "{}"), ("note.txt", "x")],
            [],
        ):
            with self.subTest(files=files), self.assertRaises(ValueError):
                read_evidence_zip(zip_data(files))

    def test_reject_malformed_content(self):
        for raw in (b"notzip", b"", b"x" * (256 * 1024 + 1),
                    zip_data([("deployment-evidence.json", "broken")])):
            with self.subTest(size=len(raw)), self.assertRaises(ValueError):
                read_evidence_zip(raw)

    def test_reject_uncompressed_zip_bomb(self):
        payload = "Z" * (128 * 1024 + 1)
        with self.assertRaises(ValueError):
            read_evidence_zip(zip_data([("deployment-evidence.json", payload)]))

    def test_reject_wrong_release_id(self):
        data = zip_data([("deployment-evidence.json",
                          json.dumps({**EVIDENCE, "release_id": "../unsafe"}))])
        with self.assertRaises(ValueError):
            read_evidence_zip(data)


if __name__ == "__main__":
    unittest.main()
