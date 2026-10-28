"""B1 passively discovers release scope; fully mocked, no network or writes."""
import io
import json
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))
from controller_postrun_b1 import discover, EXPECTED_WORKFLOW, BASE

SHA = "a" * 40
PATH = "catalogus-public/admin/index.php"
RUN = {"id": 99, "head_sha": SHA, "path": EXPECTED_WORKFLOW,
       "event": "workflow_dispatch", "head_branch": "main", "status": "completed"}
ARTIFACT = {"id": 42, "name": "shared-ovh-evidence-99", "expired": False,
            "workflow_run": {"id": 99}}
DOC = {"schema": 1, "release_id": "approved-test", "release_sha": SHA,
       "run_id": 99, "status": "VERIFIED",
       "files": [{"path": PATH, "source_sha256": "b" * 64,
                  "baseline_sha256": "ABSENT", "readback_sha256": "b" * 64}],
       "rollback_artifact": 43, "browser_acceptance": "PENDING"}


def archive(document=DOC):
    out = io.BytesIO()
    with zipfile.ZipFile(out, "w") as handle:
        handle.writestr("deployment-evidence.json", json.dumps(document))
    return out.getvalue()


class B1Tests(unittest.TestCase):
    def check(self, *, run=RUN, artifact=ARTIFACT, document=DOC,
              manifest=None, total=1):
        with tempfile.TemporaryDirectory() as temp:
            Path(temp, "approved-test.json").write_text(json.dumps(
                manifest if manifest is not None else
                {"schema": 1, "files": [{"path": PATH}]}))
            def read_json(path, token):
                if path.endswith("/artifacts?per_page=100"):
                    return {"total_count": total, "artifacts": [artifact]}
                return run
            def read_bytes(url, token, *, limit):
                self.assertEqual(url, BASE + "/actions/artifacts/42/zip")
                return archive(document)
            return discover(99, SHA, "mock", read_json=read_json,
                            read_bytes=read_bytes, manifests=Path(temp))

    def test_exact_bound_manifest(self):
        self.assertEqual(self.check(), [PATH])

    def test_untrusted_event_or_sha(self):
        for key, value in (("event", "pull_request"), ("head_branch", "feature"),
                           ("head_sha", "c" * 40), ("path", "other.yml")):
            with self.subTest(key=key), self.assertRaises(ValueError):
                self.check(run={**RUN, key: value})

    def test_missing_or_mismatched_evidence(self):
        for kwargs in (
            {"artifact": {**ARTIFACT, "expired": True}},
            {"artifact": {**ARTIFACT, "workflow_run": {"id": 98}}},
            {"document": {**DOC, "release_sha": "c" * 40}},
            {"total": 101},
            {"manifest": {"schema": 1, "files": [{"path": "private/other.php"}]}},
        ):
            with self.subTest(kwargs=kwargs.keys()), self.assertRaises(ValueError):
                self.check(**kwargs)

    def test_workflow_is_only_read_only(self):
        workflow = (ROOT / ".github/workflows/controller-postrun-b1.yml").read_text()
        self.assertIn("actions: read", workflow)
        self.assertIn("contents: read", workflow)
        self.assertIn("persist-credentials: false", workflow)
        self.assertNotIn("actions: write", workflow)
        self.assertNotIn("issues: write", workflow)
        self.assertNotIn("workflow_dispatch:", workflow)
        self.assertNotIn("environment: production", workflow)


if __name__ == "__main__":
    unittest.main()
