"""Offline tests for GitHub evidence discovery. No network used."""
import base64
import io
import json
import sys
import unittest
from pathlib import Path
from urllib.request import Request
from unittest.mock import patch
from zipfile import ZipFile
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
import ovh_release_evidence_discovery as discovery


class DiscoveryTests(unittest.TestCase):
    def test_single_evidence_member(self):
        buf = io.BytesIO()
        with ZipFile(buf, "w") as z:
            z.writestr("deployment-evidence.json", json.dumps({"status": "FAILED"}))
        self.assertEqual(discovery.read_evidence_zip(buf.getvalue())["status"], "FAILED")

    def test_reject_unexpected_zip_member(self):
        buf = io.BytesIO()
        with ZipFile(buf, "w") as z:
            z.writestr("untrusted.json", "{}")
        with self.assertRaises(ValueError):
            discovery.read_evidence_zip(buf.getvalue())

    def test_latest_success_is_not_silently_skipped_on_bad_evidence(self):
        response = {"workflow_runs": [{"id": 100, "status": "completed", "conclusion": "success"},
                                      {"id": 99, "status": "completed", "conclusion": "success"}]}
        with patch.object(discovery, "json_get", return_value=response), patch.object(
            discovery, "inspect_run", side_effect=ValueError("bad proof")
        ) as inspect:
            with self.assertRaises(ValueError):
                discovery.latest_review("dummy")
        self.assertEqual(inspect.call_count, 1)

    def test_artifact_redirect_does_not_forward_bearer(self):
        api_url = discovery.API + "/actions/artifacts/123/zip"
        original = Request(api_url, headers={"Authorization": "Bearer secret"})
        redirected = discovery.ArtifactRedirect().redirect_request(
            original, None, 302, "Found", {},
            "https://productionresultssa0.blob.core.windows.net/download?sig=123"
        )
        self.assertEqual(redirected.full_url.split("?")[0],
                         "https://productionresultssa0.blob.core.windows.net/download")
        self.assertNotIn("Authorization", redirected.headers)

    def test_refuse_untrusted_or_non_tls_artifact_redirects(self):
        original = Request(discovery.API + "/actions/artifacts/123/zip",
                           headers={"Authorization": "Bearer secret"})
        for url in ("http://productionresultssa0.blob.core.windows.net/zip",
                    "https://attacker.example/download",
                    "https://localhost/download",
                    "https://user:pass@productionresultssa0.blob.core.windows.net/zip"):
            with self.subTest(url=url), self.assertRaises(ValueError):
                discovery.ArtifactRedirect().redirect_request(
                    original, None, 302, "Found", {}, url
                )

    def test_refuse_redirect_from_non_artifact_endpoint(self):
        original = Request(discovery.API + "/actions/workflows/shared-ovh-release.yml/runs")
        with self.assertRaises(ValueError):
            discovery.ArtifactRedirect().redirect_request(
                original, None, 302, "Found", {},
                "https://productionresultssa0.blob.core.windows.net/download"
            )

    def test_recent_failed_release_blocks_old_success(self):
        response = {"workflow_runs": [
            {"id": 101, "status": "completed", "conclusion": "failure"},
            {"id": 100, "status": "completed", "conclusion": "success"},
        ]}
        with patch.object(discovery, "json_get", return_value=response), patch.object(
            discovery, "inspect_run"
        ) as inspect:
            with self.assertRaisesRegex(ValueError, "not successful"):
                discovery.latest_review("dummy")
        inspect.assert_not_called()

    def test_latest_running_release_blocks_old_success(self):
        response = {"workflow_runs": [
            {"id": 101, "status": "in_progress", "conclusion": None},
            {"id": 100, "status": "completed", "conclusion": "success"},
        ]}
        with patch.object(discovery, "json_get", return_value=response), patch.object(
            discovery, "inspect_run"
        ) as inspect:
            with self.assertRaisesRegex(ValueError, "not successful"):
                discovery.latest_review("dummy")
        inspect.assert_not_called()

    def test_full_mocked_artifact_reconciles_immutable_manifest(self):
        sha = "b" * 40
        manifest = {"schema": 1, "files": [{
            "path": "catalogus-public/admin/admin.css",
            "source_sha256": "a" * 64, "remote_sha256": "c" * 64,
        }]}
        evidence = {
            "schema": 1, "release_id": "test-abc", "release_sha": sha,
            "run_id": 123, "status": "VERIFIED", "rollback_artifact": None,
            "browser_acceptance": "PENDING",
            "files": [{
                "path": "catalogus-public/admin/admin.css",
                "source_sha256": "a" * 64, "baseline_sha256": "c" * 64,
                "readback_sha256": "a" * 64,
            }],
        }
        run = {
            "id": 123, "head_sha": sha, "event": "workflow_dispatch",
            "head_branch": "main",
            "path": "pecharko/Veiling/.github/workflows/shared-ovh-release.yml",
            "status": "completed", "conclusion": "success",
        }
        artifacts = [
            {"id": 10, "name": "shared-ovh-evidence-123", "expired": False},
            {"id": 11, "name": "shared-ovh-rollback-123", "expired": False},
        ]
        zipped = io.BytesIO()
        with ZipFile(zipped, "w") as archive:
            archive.writestr("deployment-evidence.json", json.dumps(evidence))
        def fake_json_get(url, token):
            if "/actions/runs/123/artifacts" in url:
                return {"artifacts": artifacts}
            if "/contents/releases/test-abc.json?" in url:
                return {"type": "file", "encoding": "base64",
                        "content": base64.b64encode(
                            json.dumps(manifest).encode()
                        ).decode()}
            self.fail("Unexpected API request: " + url)
        with patch.object(discovery, "json_get", side_effect=fake_json_get), patch.object(
            discovery, "get", return_value=zipped.getvalue()
        ) as get_zip:
            result = discovery.inspect_run(run, "dummy")
        self.assertEqual(result["status"], "TECHNICALLY_VERIFIED")
        self.assertEqual(result["release_sha"], sha)
        self.assertEqual(result["browser_acceptance"], "PENDING")
        self.assertEqual(get_zip.call_count, 1)

    def test_no_successful_run(self):
        with patch.object(discovery, "json_get", return_value={"workflow_runs": []}):
            with self.assertRaises(ValueError):
                discovery.latest_review("dummy")

if __name__ == "__main__":
    unittest.main()
