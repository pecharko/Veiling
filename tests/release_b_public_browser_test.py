"""Offline browser adapter unit tests; no website or user data accessed."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from release_b_public_browser import browser_check, preflight_browser_run, ORIGIN
from release_b_browser_run import plan_browser_run

class Response:
    status = 200

class Element:
    def __init__(self, visible=True):
        self.visible = visible
    @property
    def first(self):
        return self
    def is_visible(self, timeout=0):
        return self.visible

class Page:
    def __init__(self, failing=False, visible=True):
        self.visits = []
        self.url = ORIGIN + "/"
        self.failing = failing
        self.visible = visible
    def goto(self, url, **options):
        self.visits.append((url, options))
        self.url = url
        if self.failing and len(self.visits) == 2:
            return None
        return Response()
    def get_by_text(self, value, exact=False):
        return Element(self.visible)

ARGS = {"release_id": "release-example", "release_sha": "a"*40,
        "run_id": 123, "session_id": "browser-20261115-01"}

class BrowserTests(unittest.TestCase):
    def test_public_get_only(self):
        page = Page()
        report = browser_check(page, **ARGS)
        self.assertEqual(report["status"], "PASS")
        self.assertEqual(report["checks"], ["public-catalog", "artist-gallery"])
        self.assertEqual([x[0] for x in page.visits],
                         [ORIGIN + "/", ORIGIN + "/kunstenaars.php"])
        self.assertTrue(all(x[1]["wait_until"] == "domcontentloaded" for x in page.visits))

    def test_fail_closed_for_missing_page_or_heading(self):
        for page in (Page(failing=True), Page(visible=False)):
            self.assertEqual(browser_check(page, **ARGS)["status"], "FAIL")

    def test_invalid_identity_stops_before_browser(self):
        for changes in ({"release_id": "../other"}, {"release_sha": "bad"},
                        {"run_id": True}, {"session_id": "bad"}):
            page = Page()
            with self.subTest(changes=changes), self.assertRaises(ValueError):
                browser_check(page, **{**ARGS, **changes})
            self.assertEqual(page.visits, [])


RUN = {
    "id": 123, "name": "Shared OVH manifest release with rollback",
    "path": ".github/workflows/shared-ovh-release.yml",
    "repository": {"full_name": "pecharko/Veiling"},
    "event": "workflow_dispatch", "head_branch": "main",
    "status": "completed", "conclusion": "success",
    "head_sha": "a"*40, "inputs": {"release_id": "release-example"}
}
UPLOAD = {
    "schema": 1, "release_id": "release-example",
    "release_sha": "a"*40, "run_id": 123,
    "status": "VERIFIED", "rollback_artifact": None,
    "browser_acceptance": "PENDING",
    "files": [{
        "path": "catalogus-public/assets/catalogus.css",
        "source_sha256": "b"*64, "baseline_sha256": "c"*64,
        "readback_sha256": "b"*64
    }]
}

class BrowserRunBindingTest(unittest.TestCase):
    def test_valid_deployment_generates_precise_browser_parameters(self):
        self.assertEqual(
            plan_browser_run(RUN, UPLOAD, "browser-20261115-01"),
            {"release_id": "release-example", "release_sha": "a"*40,
             "run_id": 123, "session_id": "browser-20261115-01"}
        )

    def test_fail_closed_for_untrusted_or_wrong_run(self):
        modifications = [
            {"id": 124}, {"head_sha": "d"*40},
            {"event": "pull_request"}, {"head_branch": "preview/ui"},
            {"status": "in_progress"}, {"conclusion": "failure"},
            {"path": ".github/workflows/other.yml"},
            {"repository": {"full_name": "other/Veiling"}},
            {"inputs": {}}, {"inputs": {"release_id": "wrong-release"}},
            {"name": "Unknown deployment"}
        ]
        for changes in modifications:
            with self.subTest(changes=changes), self.assertRaises(ValueError):
                preflight_browser_run({**RUN, **changes}, UPLOAD)

    def test_fail_closed_for_wrong_uploader_evidence(self):
        for changes in ({"run_id": 124}, {"release_sha": "e"*40},
                        {"release_id": "other-release"}, {"status": "FAILED"}):
            with self.subTest(changes=changes), self.assertRaises(ValueError):
                plan_browser_run(RUN, {**UPLOAD, **changes}, "browser-20261115-01")

    def test_reject_missing_browser_session_before_launch(self):
        for session in ("", None):
            with self.subTest(session=session), self.assertRaises(ValueError):
                plan_browser_run(RUN, UPLOAD, session)

    def test_protected_snapshot_workflow_stays_distinct(self):
        run = {**RUN,
            "name": "Snapshot v8 catalog-only protected two-file release",
            "path": ".github/workflows/snapshot-v8-protected-release.yml"}
        self.assertEqual(preflight_browser_run(run, UPLOAD)["run_id"], 123)

if __name__ == "__main__":
    unittest.main()
