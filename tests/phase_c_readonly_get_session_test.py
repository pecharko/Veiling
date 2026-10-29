"""End-to-end Phase C mock HTTP GET-only audit; never exercises live GitHub."""
import hashlib
import json
import unittest
from urllib.error import HTTPError

from phase_c_complete_readonly_audit_test import (
    MockGitHubGET, MAIN, HEAD, CANDIDATE, NONCE,
    MAIN_PATH, BRANCH_PATH, NONCE_PATH, ref,
)
from scripts.phase_c_github_readonly_client import (
    API, ROOT, GitHubReadOnlyClient, MissingNonceRef, MAX_RESPONSE_BYTES,
)
from scripts.phase_c_readonly_get_session import (
    MAX_GET_REQUESTS, NO_AUTHORITY, audit_phase_c_get_session,
)

TEST_TOKEN = "test-token-NOT-A-LIVE-SECRET"


class FakeResponse:
    status = 200

    def __init__(self, value):
        self.value = json.dumps(value).encode("utf-8")

    def read(self, size):
        return self.value[:size]

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return False


class MockHTTPTransport:
    """Converts fixture GET paths into HTTP-like responses and 404 errors."""

    def __init__(self, fixture):
        self.fixture = fixture
        self.requests = []
        self.deny_nonce_with_403 = False
        self.drop_main_response = False

    def open(self, request, timeout):
        assert request.get_method() == "GET"
        assert request.full_url.startswith(API + ROOT)
        assert request.get_header("Authorization") == "Bearer " + TEST_TOKEN
        assert timeout == 10
        self.requests.append(request)
        path = request.full_url[len(API):]
        if self.drop_main_response and path == MAIN_PATH:
            raise HTTPError(request.full_url, 500, "outage", {}, None)
        if self.deny_nonce_with_403 and path == NONCE_PATH:
            raise HTTPError(request.full_url, 403, "forbidden", {}, None)
        try:
            response = self.fixture(path)
        except MissingNonceRef:
            raise HTTPError(request.full_url, 404, "not found", {}, None) from None
        return FakeResponse(response)


def context():
    fake = MockGitHubGET()
    transport = MockHTTPTransport(fake)
    client = GitHubReadOnlyClient(TEST_TOKEN, opener=transport)
    return fake, transport, client


def audit(client, auth, main=MAIN, candidate=CANDIDATE):
    return audit_phase_c_get_session(
        client, 901, 900, 101, auth, candidate, main,
    )


class GETOnlyPhaseCSessionTests(unittest.TestCase):
    def assertNoAuthority(self, report):
        self.assertEqual(report["git_ref_updates_executed"], 0)
        for k in NO_AUTHORITY:
            self.assertIs(report[k], False)
        self.assertLessEqual(report["get_request_count"], MAX_GET_REQUESTS)
        self.assertNotIn("query", report)
        self.assertNotIn("variables", report)
        self.assertNotIn("auth", report)
        self.assertNotIn("token", report)
        self.assertNotIn("comment", report)
        self.assertNotIn(TEST_TOKEN, json.dumps(report))
        digest = report.copy()
        actual_hash = digest.pop("report_sha256")
        encoded = json.dumps(
            digest, sort_keys=True, separators=(",", ":"), ensure_ascii=True
        ).encode("ascii")
        self.assertEqual(actual_hash, hashlib.sha256(encoded).hexdigest())

    def test_complete_get_only_session_is_advisory_even_when_all_reads_match(self):
        fake, transport, client = context()
        report = audit(client, fake.auth)
        self.assertEqual(report["decision"], "READ_ONLY_REVIEW_REQUIRED")
        self.assertEqual(report["get_request_count"], 17)
        self.assertEqual(report["nonce_status"], "404_NOT_PROOF_OF_ABSENCE")
        self.assertEqual(report["candidate_sha"], CANDIDATE)
        self.assertNoAuthority(report)
        self.assertEqual(len(transport.requests), 17)
        self.assertEqual(len(fake.calls), 17)
        self.assertTrue(all(req.get_method() == "GET"
                            for req in transport.requests))
        self.assertFalse(any("/graphql" in req.full_url or "/dispatches" in req.full_url
                             for req in transport.requests))
        # No URLs or owner comments appear in the redacted result.
        self.assertNotIn("PHASE-C-SANDBOX-ONCE", json.dumps(report))

    def test_forged_owner_and_candidate_drift_block_early(self):
        for reason in ("owner", "sha", "digest", "repo"):
            with self.subTest(reason=reason):
                fake, transport, client = context()
                auth = fake.auth.copy()
                candidate = CANDIDATE
                if reason == "owner":
                    fake.data[ROOT + "/issues/comments/101"]["user"]["id"] = 8
                elif reason == "sha":
                    candidate = "f" * 40
                elif reason == "digest":
                    auth["new_sha256"] = "f" * 64
                else:
                    fake.data[ROOT]["node_id"] = "R_kgDOOtherRepo"
                report = audit(client, auth, candidate=candidate)
                self.assertEqual(report["decision"], "DENY")
                self.assertNoAuthority(report)
                self.assertNotIn(NONCE_PATH, fake.calls)

    def test_main_and_branch_drift_and_consumed_nonce_denied(self):
        for kind in ("main", "branch", "nonce", "race"):
            with self.subTest(kind=kind):
                fake, transport, client = context()
                if kind == "main":
                    fake.data[MAIN_PATH] = ref("refs/heads/main", "e" * 40)
                elif kind == "branch":
                    fake.data[BRANCH_PATH] = ref(
                        "refs/heads/test/phase-c-sandbox-20261029", "e" * 40
                    )
                elif kind == "nonce":
                    fake.nonce_response = ref(NONCE, HEAD)
                else:
                    fake.second_main = ref("refs/heads/main", "e" * 40)
                report = audit(client, fake.auth)
                self.assertEqual(report["decision"], "DENY")
                self.assertNoAuthority(report)

    def test_denied_nonce_permission_cannot_be_mistaken_for_absence(self):
        fake, transport, client = context()
        transport.deny_nonce_with_403 = True
        report = audit(client, fake.auth)
        self.assertEqual(report["decision"], "DENY")
        self.assertNotIn("nonce_status", report)
        self.assertNoAuthority(report)

    def test_response_outage_invalid_client_and_pinned_main_fail_closed(self):
        fake, transport, client = context()
        transport.drop_main_response = True
        report = audit(client, fake.auth)
        self.assertEqual(report["decision"], "DENY")
        self.assertNoAuthority(report)
        invalid = audit(object(), fake.auth)
        self.assertEqual(invalid["decision"], "DENY")
        self.assertEqual(invalid["get_request_count"], 0)
        self.assertNoAuthority(invalid)
        fake, transport, client = context()
        drift = audit(client, fake.auth, main="not a git sha")
        self.assertEqual(drift["decision"], "DENY")
        self.assertEqual(drift["get_request_count"], 0)
        self.assertNoAuthority(drift)

    def test_report_is_stable_and_no_token_or_user_data_is_serialized(self):
        fa, ta, ca = context()
        fb, tb, cb = context()
        left = audit(ca, fa.auth)
        right = audit(cb, fb.auth)
        self.assertEqual(left, right)
        self.assertNoAuthority(left)


if __name__ == "__main__":
    unittest.main()
