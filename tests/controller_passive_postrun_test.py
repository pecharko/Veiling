#!/usr/bin/env python3
"""Offline B1 workflow-run boundary tests; no live network access."""
import base64
import io
import json
import sys
import unittest
import zipfile
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_passive_postrun import follow
from controller_run_evidence_binding import WORKFLOW

SHA = "a" * 40
DIGEST = "b" * 64
P = "catalogus-public/index.php"
RUN = {"id": 99, "head_sha": SHA, "event": "workflow_dispatch",
       "head_branch": "main", "path": WORKFLOW, "status": "completed"}
DOC = {"schema": 1, "release_id": "sample-release", "release_sha": SHA, "run_id": 99,
       "status": "VERIFIED", "rollback_artifact": 500,
       "browser_acceptance": "PENDING", "files": [{"path": P,
          "source_sha256": DIGEST, "baseline_sha256": "ABSENT", "readback_sha256": DIGEST}]}
MANIFEST = {"schema": 1, "files": [{"path": P, "source_sha256": DIGEST, "remote_sha256": "ABSENT"}]}
A = {"id": 499, "name": "shared-ovh-evidence-99", "expired": False}


def zip_doc(doc=DOC):
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as f:
        f.writestr("deployment-evidence.json", json.dumps(doc))
    return buf.getvalue()


class PassivePostRunTests(unittest.TestCase):
    def evaluate(self, *, run=RUN, doc=DOC, manifest=MANIFEST, inventory=None):
        calls = []
        def read_json(path, token):
            if path.startswith("/contents/"):
                return {"type": "file", "encoding": "base64",
                        "content": base64.b64encode(json.dumps(manifest).encode()).decode()}
            if path.endswith("/artifacts?per_page=100"):
                return {"total_count": 1, "artifacts": [A] if inventory is None else inventory}
            return run
        def read_bytes(*args, **kwargs):
            return zip_doc(doc)
        def collector(id_, sha, paths, token):
            calls.append((id_, sha, paths))
            return {"fully_accepted": False, "status": "INCOMPLETE"}
        result = follow(99, SHA, "fake", read_json=read_json, read_bytes=read_bytes,
                        collector=collector)
        return result, calls

    def test_exact_run_invokes_only_readonly_collector(self):
        result, calls = self.evaluate()
        self.assertFalse(result["fully_accepted"])
        self.assertEqual(calls, [(99, SHA, [P])])

    def test_wrong_workflow_denied(self):
        with self.assertRaises(ValueError):
            self.evaluate(run={**RUN, "path": "other.yml"})

    def test_pull_request_event_denied(self):
        with self.assertRaises(ValueError):
            self.evaluate(run={**RUN, "event": "pull_request"})

    def test_wrong_immutable_sha_denied(self):
        with self.assertRaises(ValueError):
            self.evaluate(run={**RUN, "head_sha": "c" * 40})

    def test_awaiting_artifact_incomplete(self):
        result, calls = self.evaluate(inventory=[])
        self.assertEqual(result["status"], "INCOMPLETE")
        self.assertEqual(calls, [])

    def test_manifest_evidence_disagreement_denied(self):
        with self.assertRaises(ValueError):
            self.evaluate(manifest={"schema": 1, "files": [
                {"path": "private/a.php", "source_sha256": DIGEST, "remote_sha256": "ABSENT"}]})

    def test_untrusted_zip_doc_denied(self):
        with self.assertRaises(ValueError):
            self.evaluate(doc={**DOC, "run_id": 100})


if __name__ == "__main__":
    unittest.main()
