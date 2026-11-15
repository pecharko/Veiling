"""Offline browser adapter unit tests; no website or user data accessed."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from release_b_public_browser import browser_check, ORIGIN

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

if __name__ == "__main__":
    unittest.main()
