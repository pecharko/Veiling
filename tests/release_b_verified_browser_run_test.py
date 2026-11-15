"""Offline tests of the opt-in, fail-closed browser probe wrapper."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))

from release_b_verified_browser_run import prepare_browser_probe, format_probe_report
from release_b_github_browser_preflight_test import FakeFetch, fixtures

class VerifiedProbeTests(unittest.TestCase):
    def test_prepare_from_authenticated_fixture(self):
        responses, *_ = fixtures()
        proof, identity = prepare_browser_probe(
            123, "browser-20261115-session", FakeFetch(responses)
        )
        self.assertEqual(identity["release_id"], "browser-binding-example")
        self.assertEqual(identity["run_id"], 123)
        self.assertEqual(proof["release_administration"], "OPEN")
        self.assertFalse(proof["independent_browser_origin_authenticated"])

    def test_fail_before_network_on_invalid_session(self):
        for session in (None, "", "../bad", "short"):
            fetch = FakeFetch({})
            with self.subTest(session=session), self.assertRaises(ValueError):
                prepare_browser_probe(123, session, fetch)
            self.assertEqual(fetch.calls, [])

    def test_browser_pass_is_not_authenticated_release_closeout(self):
        responses, *_ = fixtures()
        proof, identity = prepare_browser_probe(
            123, "browser-20261115-session", FakeFetch(responses)
        )
        claim = {
            "release_id": identity["release_id"],
            "release_sha": identity["release_sha"],
            "run_id": identity["run_id"],
            "session_id": identity["session_id"],
            "status": "PASS",
            "checks": ["public-catalog", "artist-gallery"],
        }
        report = format_probe_report(proof, claim)
        self.assertEqual(report["browser_observation"], "PASS")
        self.assertEqual(report["release_administration"], "OPEN")
        self.assertFalse(report["browser_origin_authenticated"])
        self.assertEqual(report["independent_ovh_readback"], "PENDING")

    def test_wrong_run_report_fails(self):
        responses, *_ = fixtures()
        proof, identity = prepare_browser_probe(
            123, "browser-20261115-session", FakeFetch(responses)
        )
        for changed in ({"run_id": 124}, {"release_sha": "f"*40},
                        {"release_id": "unrelated"}):
            claim = {**identity, **changed, "status": "PASS",
                     "checks": ["public-catalog", "artist-gallery"]}
            with self.subTest(changed=changed), self.assertRaises(ValueError):
                format_probe_report(proof, claim)

if __name__ == "__main__":
    unittest.main()
