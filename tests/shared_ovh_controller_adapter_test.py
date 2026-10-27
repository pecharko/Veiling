"""Pure shared OVH controller adapter regression tests. No credentials/network/deploy."""
from datetime import datetime, timedelta, timezone
import hashlib
import json
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from shared_ovh_controller_adapter import (
    PREFIX, build_dispatch_command, parse_approval, review_offline,
)

NOW = datetime(2026, 10, 27, 13, 8, tzinfo=timezone.utc)
PRE_SHA = "a" * 40
MAIN_SHA = "b" * 40
SOURCE = "c" * 64
REMOTE = "d" * 64
ISSUE_NUMBER = 512
RUN_ID = 400


def as_bytes(obj):
    return (json.dumps(obj, indent=2) + "\n").encode()


def fmt(d):
    return d.isoformat().replace("+00:00", "Z")


def fixture():
    files = [
        {"path": "catalogus-public/admin/admin.css",
         "source_sha256": SOURCE, "remote_sha256": REMOTE},
        {"path": "catalogus-public/admin/rollen.php",
         "source_sha256": SOURCE, "remote_sha256": "ABSENT"},
    ]
    manifest = as_bytes({"schema": 1, "files": files})
    request = as_bytes({"schema": 1, "paths": [f["path"] for f in files]})
    approval_text = "\n".join((
        PREFIX,
        "release_id=shared-demo",
        "release_sha=" + MAIN_SHA,
        "preflight_run_id=" + str(RUN_ID),
        "preflight_sha=" + PRE_SHA,
        "manifest_sha256=" + hashlib.sha256(manifest).hexdigest(),
        *(f"file={f['path']}|{f['source_sha256']}|{f['remote_sha256']}" for f in files),
    ))
    issue = {
        "number": ISSUE_NUMBER,
        "state": "open",
        "title": "DEPLOY-AUTH: shared-ovh-release/shared-demo",
        "user": {"login": "pecharko"},
        "labels": [{"name": "approved-deploy-request"}],
        "created_at": fmt(NOW - timedelta(hours=2)),
    }
    comment = {
        "user": {"login": "pecharko"},
        "body": approval_text,
        "issue_url": f"https://api.github.com/repos/pecharko/Veiling/issues/{ISSUE_NUMBER}",
        "created_at": fmt(NOW - timedelta(minutes=5)),
        "updated_at": fmt(NOW - timedelta(minutes=5)),
    }
    run = {
        "id": RUN_ID,
        "event": "push",
        "head_sha": PRE_SHA,
        "head_branch": "main",
        "status": "completed",
        "conclusion": "success",
        "path": ".github/workflows/shared-ovh-auto-manifest.yml@main",
        "created_at": fmt(NOW - timedelta(minutes=15)),
    }
    return {
        "text": approval_text,
        "issue": issue,
        "comment": comment,
        "previously_closed": False,
        "run": run,
        "artifact_inventory": [
            {"id": 321, "name": f"readonly-release-{RUN_ID}",
             "expired": False, "workflow_run": {"id": RUN_ID}}
        ],
        "artifact_files": {
            "shared-ovh-readonly-baselines.json": manifest,
            "releases/shared-demo.json": manifest,
        },
        "manifest_bytes": manifest,
        "request_at_main": request,
        "request_at_preflight": request,
        "current_main_sha": MAIN_SHA,
        "source_hashes": {f["path"]: f["source_sha256"] for f in files},
        "now": NOW,
    }


def run_review(data):
    return review_offline(data["text"], **{k: v for k, v in data.items() if k != "text"})


class AdapterOfflineTests(unittest.TestCase):
    def test_happy_path_is_inert_and_uses_only_two_shared_workflow_inputs(self):
        data = fixture()
        result = run_review(data)
        self.assertEqual(result["status"], "OFFLINE_CONTRACT_VERIFIED")
        self.assertIs(result["dispatch_started"], False)
        self.assertEqual(result["browser_acceptance"], "PENDING")
        self.assertEqual(result["approved_files"], list(data["source_hashes"]))
        self.assertEqual(
            result["dispatch_command"],
            ("gh", "workflow", "run", "shared-ovh-release.yml",
             "--repo", "pecharko/Veiling", "--ref", "main",
             "-f", "release_id=shared-demo",
             "-f", "confirmation=DEPLOY-shared-demo-" + MAIN_SHA),
        )
        self.assertNotIn("mode=deploy", result["dispatch_command"])
        self.assertNotIn("release_sha=" + MAIN_SHA, result["dispatch_command"])
        self.assertNotEqual(data["run"]["head_sha"], result["release_sha"],
                            "preflight SHA is from before manifest merge")

    def test_accept_documented_workflow_run_paths_on_main(self):
        for path in (
            ".github/workflows/shared-ovh-auto-manifest.yml@main",
            ".github/workflows/shared-ovh-auto-manifest.yml@refs/heads/main",
            "pecharko/Veiling/.github/workflows/shared-ovh-auto-manifest.yml@main",
            ".github/workflows/shared-ovh-auto-manifest.yml",
        ):
            with self.subTest(path=path):
                data = fixture()
                data["run"]["path"] = path
                self.assertEqual(run_review(data)["status"], "OFFLINE_CONTRACT_VERIFIED")

    def test_missing_or_wrong_owner_is_never_accepted(self):
        cases = (
            ("issue", "user", {"login": "attacker"}),
            ("comment", "user", {"login": "github-actions[bot]"}),
            ("issue", "state", "closed"),
            ("issue", "title", "DEPLOY-AUTH: shared-ovh-release/another"),
            ("issue", "labels", []),
            ("issue", "pull_request", {"url": "https://api.github.com/pulls/1"}),
            ("comment", "issue_url", "https://api.github.com/repos/pecharko/Veiling/issues/999"),
            ("comment", "updated_at", fmt(NOW - timedelta(minutes=1))),
        )
        for field, key, value in cases:
            with self.subTest(field=field, key=key):
                data = fixture()
                data[field][key] = value
                with self.assertRaises(ValueError):
                    run_review(data)

    def test_close_event_is_one_time_gate(self):
        for closed in (True, None, 0):
            with self.subTest(closed=closed):
                data = fixture()
                data["previously_closed"] = closed
                with self.assertRaises(ValueError):
                    run_review(data)

    def test_approval_time_and_preflight_age_fail_closed(self):
        for field, time_key, stamp in (
            ("comment", "created_at", fmt(NOW - timedelta(days=2))),
            ("run", "created_at", fmt(NOW - timedelta(days=2))),
            ("comment", "created_at", fmt(NOW + timedelta(minutes=5))),
            ("run", "created_at", fmt(NOW + timedelta(minutes=5))),
        ):
            with self.subTest(field=field, time=time_key, stamp=stamp):
                data = fixture()
                data[field][time_key] = stamp
                if field == "comment":
                    data["comment"]["updated_at"] = stamp
                with self.assertRaises(ValueError):
                    run_review(data)

    def test_reject_preflight_from_wrong_context_or_sha(self):
        cases = (
            ("id", RUN_ID + 1),
            ("head_sha", MAIN_SHA),
            ("head_branch", "develop"),
            ("event", "pull_request"),
            ("status", "in_progress"),
            ("conclusion", "failure"),
            ("path", ".github/workflows/other.yml@main"),
            ("path", ".github/workflows/shared-ovh-auto-manifest.yml@staging"),
            ("path", "other/repo/.github/workflows/shared-ovh-auto-manifest.yml@main"),
        )
        for key, val in cases:
            with self.subTest(key=key, val=val):
                data = fixture()
                data["run"][key] = val
                with self.assertRaises(ValueError):
                    run_review(data)

    def test_artifact_inventory_is_required_and_unexpired(self):
        for inv in (
            [],
            [{"id": 1, "name": "wrong-name", "expired": False}],
            [{"id": 0, "name": f"readonly-release-{RUN_ID}", "expired": False}],
            [{"id": 1, "name": f"readonly-release-{RUN_ID}", "expired": True}],
            [{"id": 1, "name": f"readonly-release-{RUN_ID}", "expired": False,
              "workflow_run": {"id": 999}}],
        ):
            with self.subTest(inv=inv):
                data = fixture()
                data["artifact_inventory"] = inv
                with self.assertRaises(ValueError):
                    run_review(data)

    def test_approval_requires_exact_file_baselines(self):
        data = fixture()
        data["text"] = data["text"].replace(REMOTE, "e" * 64)
        data["comment"]["body"] = data["text"]
        with self.assertRaisesRegex(ValueError, "Approved allowlist"):
            run_review(data)

    def test_manifest_hash_cannot_change_after_approval(self):
        data = fixture()
        data["manifest_bytes"] += b" "
        with self.assertRaisesRegex(ValueError, "digest"):
            run_review(data)

    def test_reject_preflight_proof_bytes_that_disagree_with_reviewed_manifest(self):
        data = fixture()
        data["artifact_files"]["shared-ovh-readonly-baselines.json"] = b"{}"
        with self.assertRaisesRegex(ValueError, "Artifact bytes"):
            run_review(data)

    def test_reject_missing_or_unexpected_artifact_members(self):
        for members in (
            {"shared-ovh-readonly-baselines.json": fixture()["manifest_bytes"]},
            {"shared-ovh-readonly-baselines.json": fixture()["manifest_bytes"],
             "releases/shared-demo.json": fixture()["manifest_bytes"],
             "secret.json": b"x"},
        ):
            with self.subTest(members=members.keys()):
                data = fixture()
                data["artifact_files"] = members
                with self.assertRaises(ValueError):
                    run_review(data)

    def test_source_hash_must_be_recomputed_at_current_main(self):
        data = fixture()
        data["source_hashes"]["catalogus-public/admin/admin.css"] = "e" * 64
        with self.assertRaisesRegex(ValueError, "Current main source"):
            run_review(data)

    def test_main_sha_mismatch_cannot_inherit_approval(self):
        data = fixture()
        data["current_main_sha"] = "f" * 40
        with self.assertRaisesRegex(ValueError, "Main SHA drifted"):
            run_review(data)

    def test_preflight_request_must_survive_manifest_merge_unmodified(self):
        data = fixture()
        data["request_at_preflight"] = b'{"schema":1,"paths":[]}'
        with self.assertRaises(ValueError):
            run_review(data)

    def test_unknown_duplicate_or_unsafe_approval_fields_fail_closed(self):
        original = fixture()["text"]
        mutated = (
            original + "\nunknown=yes",
            original + "\n" + original.split("\n")[-1],
            original.replace("file=catalogus-public/admin/admin.css",
                             "file=private/config.php"),
            original.replace("release_id=shared-demo", "release_id=../secret"),
            original.replace("preflight_run_id=400", "preflight_run_id=0"),
            original.replace("preflight_sha=" + PRE_SHA, "preflight_sha=" + MAIN_SHA + "x"),
        )
        for text in mutated:
            with self.subTest(suffix=text[-55:]):
                data = fixture()
                data["text"] = text
                data["comment"]["body"] = text
                with self.assertRaises(ValueError):
                    run_review(data)

    def test_duplicate_json_properties_rejected_even_with_matching_hash(self):
        data = fixture()
        malformed = (
            b'{"schema":1,"schema":1,"files":[]}'
        )
        data["manifest_bytes"] = malformed
        data["text"] = data["text"].replace(
            hashlib.sha256(fixture()["manifest_bytes"]).hexdigest(),
            hashlib.sha256(malformed).hexdigest(),
        )
        data["comment"]["body"] = data["text"]
        with self.assertRaisesRegex(ValueError, "Duplicate JSON"):
            run_review(data)

    def test_missing_awareness_or_edited_comment_fails(self):
        data = fixture()
        data["now"] = NOW.replace(tzinfo=None)
        with self.assertRaisesRegex(ValueError, "aware"):
            run_review(data)
        data = fixture()
        data["comment"]["body"] = "unrelated"
        with self.assertRaises(ValueError):
            run_review(data)

    def test_build_command_does_not_use_shell(self):
        approved = parse_approval(fixture()["text"])
        command = build_dispatch_command(approved)
        self.assertEqual(command[0:4],
                         ("gh", "workflow", "run", "shared-ovh-release.yml"))
        self.assertNotIn("bash", command)
        self.assertEqual(command[-1], "confirmation=DEPLOY-shared-demo-" + MAIN_SHA)


if __name__ == "__main__":
    unittest.main()
