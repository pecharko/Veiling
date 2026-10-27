"""No-network regression tests for the shared OVH controller evidence reader."""
import base64
from datetime import timedelta
import hashlib
import io
import json
import stat
import sys
import unittest
from pathlib import Path
from unittest.mock import patch
import zipfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))
sys.path.insert(0, str(ROOT / "tests"))
import shared_ovh_controller_evidence as reader
from shared_ovh_controller_adapter import PREFIX, PR_CREATION_STEP
from shared_ovh_controller_adapter_test import (
    fixture, recovered_preflight_fixture, NOW, PRE_SHA, MAIN_SHA, RUN_ID,
)


def encode(obj):
    return json.dumps(obj, separators=(",", ":")).encode()


def archive(files):
    target = io.BytesIO()
    with zipfile.ZipFile(target, "w", compression=zipfile.ZIP_DEFLATED) as output:
        for name, data in files.items():
            output.writestr(name, data)
    return target.getvalue()


def git_item(data):
    return {
        "type": "file",
        "encoding": "base64",
        "size": len(data),
        "content": base64.b64encode(data).decode(),
    }


def make_api(red=False):
    data = recovered_preflight_fixture() if red else fixture()
    source_contents = {
        "catalogus-public/admin/admin.css": b"body { color: red }\n",
        "catalogus-public/admin/rollen.php": b"<?php echo 'rollen';\n",
    }
    manifest = json.loads(data["manifest_bytes"])
    for row in manifest["files"]:
        row["source_sha256"] = hashlib.sha256(source_contents[row["path"]]).hexdigest()
    manifest_bytes = (json.dumps(manifest, indent=2) + "\n").encode()
    request = data["request_at_main"]
    approval = "\n".join((
        PREFIX,
        "release_id=shared-demo",
        "release_sha=" + MAIN_SHA,
        "preflight_run_id=" + str(RUN_ID),
        "preflight_sha=" + PRE_SHA,
        "manifest_sha256=" + hashlib.sha256(manifest_bytes).hexdigest(),
        *(f"file={r['path']}|{r['source_sha256']}|{r['remote_sha256']}"
          for r in manifest["files"]),
    ))
    data["comment"]["body"] = approval
    data["comment"]["id"] = 77
    data["artifact_files"] = {
        "shared-ovh-readonly-baselines.json": manifest_bytes,
        "releases/shared-demo.json": manifest_bytes,
    }
    responses = {
        "/branches/main": {"commit": {"sha": MAIN_SHA}},
        "/issues/512": data["issue"],
        "/issues/comments/77": data["comment"],
        "/issues/512/events?per_page=100": [],
        "/actions/runs/400": data["run"],
        "/actions/runs/400/artifacts?per_page=100": {
            "total_count": 1, "artifacts": data["artifact_inventory"],
        },
        "/actions/runs/400/jobs?per_page=100": {
            "total_count": 1, "jobs": data.get("preflight_jobs") or [],
        },
    }
    files = {}
    def add_file(path, ref, blob):
        files[f"/contents/{path}?ref={ref}"] = git_item(blob)
    add_file("releases/shared-demo.json", MAIN_SHA, manifest_bytes)
    add_file("release-requests/shared-demo.json", MAIN_SHA, request)
    add_file("release-requests/shared-demo.json", PRE_SHA, request)
    for path, raw in source_contents.items():
        add_file(path, MAIN_SHA, raw)
    responses.update(files)
    responses["/actions/artifacts/321/zip"] = archive(data["artifact_files"])
    return responses, data


class FakeGitHub:
    def __init__(self, responses):
        self.responses = responses
        self.called = []
    def __call__(self, url, token):
        if token != "synthetic-token":
            raise AssertionError("Unexpected token")
        if not url.startswith(reader.BASE + "/"):
            raise AssertionError("Attempted request outside fixed GitHub repository")
        path = url[len(reader.BASE):]
        self.called.append(path)
        if path not in self.responses:
            raise ValueError("Missing mocked read-only endpoint " + path)
        response = self.responses[path]
        if isinstance(response, Exception):
            raise response
        return response if isinstance(response, bytes) else encode(response)


def review(responses):
    fake = FakeGitHub(responses)
    with patch.object(reader, "github_get", side_effect=fake):
        return reader.collect_review("synthetic-token", 512, 77, now=NOW), fake.called


class GitHubEvidenceReadOnlyTests(unittest.TestCase):
    def test_full_green_run_reads_evidence_and_produces_only_inert_plan(self):
        result, calls = review(make_api()[0])
        self.assertEqual(result["status"], "OFFLINE_CONTRACT_VERIFIED")
        self.assertEqual(result["preflight_outcome"], "SUCCESSFUL_PREFLIGHT")
        self.assertIs(result["dispatch_started"], False)
        self.assertEqual(result["browser_acceptance"], "PENDING")
        self.assertEqual(result["dispatch_command"][-1],
                         "confirmation=DEPLOY-shared-demo-" + MAIN_SHA)
        self.assertFalse(any("/jobs?" in c for c in calls))
        self.assertTrue(any("/releases/shared-demo.json" in c for c in calls))

    def test_real_world_bot_pr_creation_failure_recovers_only_verified_baseline(self):
        result, calls = review(make_api(red=True)[0])
        self.assertEqual(result["status"], "OFFLINE_CONTRACT_VERIFIED")
        self.assertEqual(result["preflight_outcome"],
                         "BASELINE_VERIFIED_PR_CREATION_FAILED")
        self.assertIs(result["dispatch_started"], False)
        self.assertIn("/actions/runs/400/jobs?per_page=100", calls)

    def test_red_run_with_failed_sftp_or_artifact_step_still_stops(self):
        for name in (
            "Collect real remote hashes and generate valid manifest",
            "Upload proof artifact even if GitHub cannot create PR",
            "Verify main remains source",
        ):
            with self.subTest(name=name):
                responses, data = make_api(red=True)
                job = responses["/actions/runs/400/jobs?per_page=100"]["jobs"][0]
                next(s for s in job["steps"] if s["name"] == name)["conclusion"] = "failure"
                with self.assertRaises(ValueError):
                    review(responses)

    def test_missing_proof_of_red_run_stops(self):
        responses, _ = make_api(red=True)
        responses["/actions/runs/400/jobs?per_page=100"]["jobs"] = []
        with self.assertRaises(ValueError):
            review(responses)

    def test_issue_was_previously_closed_stops_even_if_reopened(self):
        responses, _ = make_api()
        responses["/issues/512/events?per_page=100"] = [
            {"event": "closed"}, {"event": "reopened"}
        ]
        with self.assertRaisesRegex(ValueError, "consumed"):
            review(responses)

    def test_issue_event_history_pagination_fails_closed(self):
        responses, _ = make_api()
        responses["/issues/512/events?per_page=100"] = [
            {"event": "labeled"} for _ in range(100)
        ]
        with self.assertRaisesRegex(ValueError, "unbounded"):
            review(responses)

    def test_wrong_comment_identity_is_not_owner_authorization(self):
        responses, _ = make_api()
        responses["/issues/comments/77"]["user"]["login"] = "github-actions[bot]"
        with self.assertRaises(ValueError):
            review(responses)

    def test_incorrect_comment_id_does_not_inherit_valid_approval(self):
        responses, _ = make_api()
        responses["/issues/comments/77"]["id"] = 78
        with self.assertRaisesRegex(ValueError, "comment ID mismatch"):
            review(responses)

    def test_main_sha_change_blocks_stale_authorization(self):
        responses, _ = make_api()
        responses["/branches/main"]["commit"]["sha"] = "f" * 40
        with self.assertRaises(ValueError):
            review(responses)

    def test_source_bytes_must_match_reviewed_sha(self):
        responses, _ = make_api()
        responses["/contents/catalogus-public/admin/admin.css?ref=" + MAIN_SHA] = git_item(
            b"body { malicious: true }\n"
        )
        with self.assertRaisesRegex(ValueError, "source"):
            review(responses)

    def test_request_mutation_since_preflight_blocks(self):
        responses, _ = make_api()
        responses["/contents/release-requests/shared-demo.json?ref=" + PRE_SHA] = git_item(
            b'{"schema":1,"paths":[]}'
        )
        with self.assertRaises(ValueError):
            review(responses)

    def test_missing_artifact_blocks_without_old_success_fallback(self):
        responses, _ = make_api()
        responses["/actions/runs/400/artifacts?per_page=100"]["artifacts"] = []
        with self.assertRaises(ValueError):
            review(responses)

    def test_artifact_zip_with_extra_entries_is_rejected(self):
        responses, data = make_api()
        files = dict(data["artifact_files"])
        files["unexpected-secret"] = b"x"
        responses["/actions/artifacts/321/zip"] = archive(files)
        with self.assertRaisesRegex(ValueError, "ZIP members"):
            review(responses)

    def test_artifact_zip_missing_or_altered_evidence_is_rejected(self):
        for case in ("missing", "altered"):
            with self.subTest(case=case):
                responses, data = make_api()
                files = dict(data["artifact_files"])
                if case == "missing":
                    del files["shared-ovh-readonly-baselines.json"]
                else:
                    files["shared-ovh-readonly-baselines.json"] = b"{}"
                responses["/actions/artifacts/321/zip"] = archive(files)
                with self.assertRaises(ValueError):
                    review(responses)

    def test_malicious_symlink_artifact_member_rejected(self):
        responses, data = make_api()
        output = io.BytesIO()
        with zipfile.ZipFile(output, "w") as z:
            for name, blob in data["artifact_files"].items():
                info = zipfile.ZipInfo(name)
                if name == "shared-ovh-readonly-baselines.json":
                    info.external_attr = (stat.S_IFLNK | 0o777) << 16
                z.writestr(info, blob)
        responses["/actions/artifacts/321/zip"] = output.getvalue()
        with self.assertRaisesRegex(ValueError, "Unsafe read-only evidence"):
            review(responses)

    def test_malformed_immutable_contents_metadata_rejected(self):
        responses, _ = make_api()
        path = "/contents/releases/shared-demo.json?ref=" + MAIN_SHA
        responses[path]["encoding"] = "none"
        with self.assertRaisesRegex(ValueError, "Invalid immutable"):
            review(responses)

    def test_truncated_immutable_file_bytes_rejected(self):
        responses, _ = make_api()
        path = "/contents/releases/shared-demo.json?ref=" + MAIN_SHA
        responses[path]["size"] += 1
        with self.assertRaisesRegex(ValueError, "size mismatch"):
            review(responses)

    def test_git_api_error_fails_closed(self):
        responses, _ = make_api()
        responses["/actions/runs/400"] = ValueError("synthetic API outage")
        with self.assertRaisesRegex(ValueError, "synthetic API outage"):
            review(responses)

    def test_wrong_readonly_run_status_never_returns_green(self):
        for status, conclusion in (
            ("completed", "cancelled"),
            ("queued", None),
            ("in_progress", None),
        ):
            with self.subTest(status=status, conclusion=conclusion):
                responses, _ = make_api()
                responses["/actions/runs/400"]["status"] = status
                responses["/actions/runs/400"]["conclusion"] = conclusion
                with self.assertRaises(ValueError):
                    review(responses)

    def test_no_io_on_import_and_no_shell_invocation(self):
        # The module itself is importable, its only externally callable flow
        # requires an explicitly passed token and IDs, and returns an inert plan.
        self.assertFalse(hasattr(reader, "dispatch_approved"))
        self.assertFalse(hasattr(reader, "subprocess"))
        self.assertFalse(hasattr(reader, "main"))
        self.assertTrue(callable(reader.collect_review))


if __name__ == "__main__":
    unittest.main()
