"""Offline GitHub evidence-chain security tests (no HTTP, SFTP or browser)."""
import base64
import io
import json
import sys
import unittest
import zipfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from release_b_github_browser_preflight import (
    collect_verified_browser_inputs, manifest_content, api_get,
)

RID = "browser-binding-example"
SHA = "a" * 40
SOURCE = "b" * 64
BASELINE = "c" * 64
REPO = "repos/pecharko/Veiling"

def serialize(data):
    return json.dumps(data, separators=(",", ":")).encode()

def fixtures():
    run = {
        "id": 123,
        "name": "Shared OVH manifest release with rollback",
        "path": ".github/workflows/shared-ovh-release.yml",
        "repository": {"full_name": "pecharko/Veiling"},
        "event": "workflow_dispatch",
        "head_branch": "main",
        "head_sha": SHA,
        "status": "completed",
        "conclusion": "success",
    }
    evidence = {
        "schema": 1,
        "release_id": RID,
        "release_sha": SHA,
        "run_id": 123,
        "status": "VERIFIED",
        "rollback_artifact": None,
        "browser_acceptance": "PENDING",
        "files": [{
            "path": "catalogus-public/assets/catalogus.css",
            "source_sha256": SOURCE,
            "baseline_sha256": BASELINE,
            "readback_sha256": SOURCE,
        }],
    }
    manifest = {"schema": 1, "files": [{
        "path": "catalogus-public/assets/catalogus.css",
        "source_sha256": SOURCE, "remote_sha256": BASELINE
    }]}
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w", compression=zipfile.ZIP_DEFLATED) as writer:
        writer.writestr("deployment-evidence.json", serialize(evidence))
    raw_zip = buffer.getvalue()
    artifact = {
        "name": "shared-ovh-evidence-123",
        "id": 55, "expired": False,
        "size_in_bytes": len(raw_zip),
        "workflow_run": {"id": 123}
    }
    artifact_list = {"total_count": 1, "artifacts": [artifact]}
    response = {
        "type": "file", "encoding": "base64",
        "size": len(serialize(manifest)),
        "content": base64.b64encode(serialize(manifest)).decode(),
    }
    jobs = {"total_count": 1, "jobs": [{
        "id": 456, "run_id": 123, "name": "release",
        "status": "completed", "conclusion": "success",
        "steps": [
            {"name": "Offline manifest validation and source hashes",
             "conclusion": "success"},
            {"name": "Verify exact per-release final authorization",
             "conclusion": "success"},
            {"name": "Backup and upload exactly manifest files with drift gate and rollback",
             "conclusion": "success"},
        ]
    }]}
    log = (
        f"2026-11-14T23:08:20.6803142Z   RELEASE_ID: {RID}\\n"
        f"2026-11-14T23:08:20.6804216Z   DEPLOY_CONFIRMATION: DEPLOY-{RID}-{SHA}\\n"
    ).encode()
    responses = {
        f"{REPO}/actions/runs/123": serialize(run),
        f"{REPO}/actions/runs/123/jobs?per_page=100": serialize(jobs),
        f"{REPO}/actions/jobs/456/logs": log,
        f"{REPO}/actions/runs/123/artifacts?per_page=100": serialize(artifact_list),
        f"{REPO}/actions/artifacts/55/zip": raw_zip,
        f"{REPO}/contents/releases/{RID}.json?ref={SHA}": serialize(response),
    }
    return responses, run, evidence, manifest, artifact_list, response

class FakeFetch:
    def __init__(self, values):
        self.values = values
        self.calls = []
    def __call__(self, path, *, max_bytes):
        self.calls.append((path, max_bytes))
        value = self.values[path]
        if len(value) > max_bytes:
            raise ValueError("Response over limit")
        return value

class GitHubProvenanceTests(unittest.TestCase):
    def test_read_only_six_fetches_and_open_status(self):
        responses, *_ = fixtures()
        fetch = FakeFetch(responses)
        result = collect_verified_browser_inputs(123, fetch)
        self.assertEqual(result["release_sha"], SHA)
        self.assertEqual(result["github_artifact_id"], 55)
        self.assertEqual(result["github_provenance"], "GITHUB_API_GET_SOURCE_BOUND")
        self.assertFalse(result["independent_browser_origin_authenticated"])
        self.assertEqual(result["release_administration"], "OPEN")
        self.assertEqual(len(fetch.calls), 6)
        self.assertFalse(result["original_dispatch_inputs_authenticated"])
        self.assertEqual(result["release_job_id"], 456)
        self.assertTrue(all(path.startswith(REPO + "/") for path, _ in fetch.calls))

    def test_wrong_run_rejected_before_artifact_fetch(self):
        for update in (
            {"id": 124}, {"event": "pull_request"}, {"head_branch": "preview/ui"},
            {"status": "in_progress"}, {"conclusion": "failure"},
            {"head_sha": "bad"}, {"repository": {"full_name": "other/repo"}},
        ):
            values, run, *_ = fixtures()
            values[f"{REPO}/actions/runs/123"] = serialize({**run, **update})
            fetch = FakeFetch(values)
            with self.subTest(update=update), self.assertRaises(ValueError):
                collect_verified_browser_inputs(123, fetch)
            self.assertLessEqual(len(fetch.calls), 3)

    def test_wrong_dispatch_release_id(self):
        values, run, *_ = fixtures()
        values[f"{REPO}/actions/runs/123"] = serialize({
            **run, "inputs": {"release_id": "other-id"}
        })
        with self.assertRaises(ValueError):
            collect_verified_browser_inputs(123, FakeFetch(values))

    def test_successful_job_provenance_fail_closed(self):
        values, *_ = fixtures()
        jobs_path = f"{REPO}/actions/runs/123/jobs?per_page=100"
        log_path = f"{REPO}/actions/jobs/456/logs"
        baseline = json.loads(values[jobs_path])
        log = values[log_path]
        cases = [
            ({"total_count": 0, "jobs": []}, log),
            ({"total_count": 2, "jobs": baseline["jobs"] * 2}, log),
            ({"total_count": 1, "jobs": [
                {**baseline["jobs"][0], "conclusion": "failure"}]}, log),
            ({"total_count": 1, "jobs": [
                {**baseline["jobs"][0], "run_id": 124}]}, log),
            (baseline, b""),
            (baseline, log.replace(RID.encode(), b"wrong-release")),
            (baseline, log.replace(SHA.encode(), ("f" * 40).encode())),
            (baseline, log + b"2026-11-14T23:08:30Z   RELEASE_ID: wrong-release\\n"),
        ]
        for jobs, log_bytes in cases:
            fixture, *_ = fixtures()
            fixture[jobs_path] = serialize(jobs)
            fixture[log_path] = log_bytes
            with self.subTest(jobs=jobs, log=log_bytes[:30]), self.assertRaises(ValueError):
                collect_verified_browser_inputs(123, FakeFetch(fixture))

    def test_run_inputs_conflict_with_authenticated_job_environment(self):
        values, run, *_ = fixtures()
        values[f"{REPO}/actions/runs/123"] = serialize({
            **run, "inputs": {"release_id": "other-id"}
        })
        with self.assertRaises(ValueError):
            collect_verified_browser_inputs(123, FakeFetch(values))

    def test_disallow_absent_or_ambiguous_artifact(self):
        for replacement in (
            {"total_count": 0, "artifacts": []},
            {"total_count": 2, "artifacts": [
                fixtures()[4]["artifacts"][0],
                fixtures()[4]["artifacts"][0],
            ]},
            {"total_count": 101, "artifacts": []},
        ):
            values, *_ = fixtures()
            values[f"{REPO}/actions/runs/123/artifacts?per_page=100"] = serialize(replacement)
            with self.subTest(replacement=replacement), self.assertRaises(ValueError):
                collect_verified_browser_inputs(123, FakeFetch(values))

    def test_reject_manifest_hash_drift(self):
        values, *_ = fixtures()
        manifest = {"schema": 1, "files": [{
            "path": "catalogus-public/assets/catalogus.css",
            "source_sha256": "d"*64, "remote_sha256": BASELINE,
        }]}
        response = {
            "type": "file", "encoding": "base64",
            "size": len(serialize(manifest)),
            "content": base64.b64encode(serialize(manifest)).decode(),
        }
        values[f"{REPO}/contents/releases/{RID}.json?ref={SHA}"] = serialize(response)
        with self.assertRaises(ValueError):
            collect_verified_browser_inputs(123, FakeFetch(values))

    def test_reject_changed_zip_content(self):
        values, *_ = fixtures()
        values[f"{REPO}/actions/artifacts/55/zip"] = b"not a real zip"
        with self.assertRaises(ValueError):
            collect_verified_browser_inputs(123, FakeFetch(values))

    def test_manifest_malformed(self):
        with self.assertRaises(ValueError):
            manifest_content({"type": "file", "encoding": "base64",
                              "size": 10, "content": "***notbase64***"})

    def test_invalid_run_id_stops_before_any_fetch(self):
        for value in (0, -1, True, "123"):
            fetch = FakeFetch({})
            with self.subTest(value=value), self.assertRaises(ValueError):
                collect_verified_browser_inputs(value, fetch)
            self.assertEqual(fetch.calls, [])

    def test_api_transport_blocks_untrusted_route_before_subprocess(self):
        with self.assertRaises(ValueError):
            api_get("https://example.com/private", max_bytes=2048)
        with self.assertRaises(ValueError):
            api_get("repos/other/Veiling/actions/runs/1", max_bytes=2048)

if __name__ == "__main__":
    unittest.main()
