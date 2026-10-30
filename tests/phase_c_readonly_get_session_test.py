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
    audit_repair_get_session,
)
from scripts.development_ci_write_gate import _git_blob_sha
import base64
import copy

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


def repair_fixture():
    original = "<?php\nfunction phase_c(): bool {\n    return flase;\n}\n"
    head = "a" * 40
    req = {
        "pr": 42,
        "expected_head_sha": head,
        "actual_head_sha": head,
        "branch": "test/phase-c-freshfixture",
        "path": "tests/phase_c_fresh_test.php",
        "base_blob_sha": _git_blob_sha(original),
        "replacement": original.replace("return flase;", "return false;"),
        "attempts": 0,
        "author_approved": True,
    }
    repo = {"full_name": "pecharko/Veiling"}
    pr = {
        "number": 42, "state": "open", "draft": True, "merged_at": None,
        "head": {"repo": repo, "sha": head, "ref": req["branch"]},
        "base": {"repo": repo, "sha": "b" * 40},
    }
    branch = {
        "ref": "refs/heads/" + req["branch"],
        "object": {"type": "commit", "sha": head},
    }
    blob = {
        "type": "file", "path": req["path"], "sha": req["base_blob_sha"],
        "encoding": "base64", "size": len(original.encode("utf-8")),
        "content": base64.b64encode(original.encode("utf-8")).decode("ascii"),
    }
    paths = {
        ROOT + "/pulls/42": pr,
        ROOT + "/git/ref/heads/" + req["branch"]: branch,
        ROOT + "/contents/" + req["path"] + "?ref=" + head: blob,
    }
    return req, paths


class RepairHTTP:
    def __init__(self, paths, drift=None):
        self.paths = paths
        self.drift = drift
        self.calls = []
        self.count = {}

    def open(self, request, timeout):
        assert request.get_method() == "GET"
        assert request.full_url.startswith(API + ROOT)
        assert request.get_header("Authorization") == "Bearer " + TEST_TOKEN
        assert timeout == 10
        path = request.full_url[len(API):]
        self.calls.append(path)
        self.count[path] = self.count.get(path, 0) + 1
        if path not in self.paths:
            raise HTTPError(request.full_url, 404, "unavailable", {}, None)
        data = copy.deepcopy(self.paths[path])
        if self.drift and self.count[path] == 2 and path.endswith(
                "/git/ref/heads/test/phase-c-freshfixture"):
            data["object"]["sha"] = "f" * 40
        return FakeResponse(data)


class RepairGETOnlySessionTests(unittest.TestCase):
    def test_six_gets_and_no_authorization_even_when_all_sources_match(self):
        request, data = repair_fixture()
        transport = RepairHTTP(data)
        client = GitHubReadOnlyClient(TEST_TOKEN, opener=transport)
        report = audit_repair_get_session(client, request)
        self.assertEqual(report["decision"], "READ_ONLY_REVIEW")
        self.assertTrue(report["readback_structurally_consistent"])
        self.assertEqual(report["get_request_count"], 6)
        self.assertEqual(report["head_sha"], request["expected_head_sha"])
        self.assertEqual(len(report["review_sha256"]), 64)
        self.assertEqual(len(report["report_sha256"]), 64)
        self.assertEqual(report["git_ref_updates_executed"], 0)
        self.assertEqual(len(transport.calls), 6)
        self.assertTrue(all(x in data for x in transport.calls))
        for key in ("can_write", "can_merge", "can_dispatch",
                    "production_authorized", "owner_identity_verified",
                    "nonce_reserved", "source_provenance_authenticated",
                    "write_allowed", "retry_allowed"):
            self.assertFalse(report[key])
        self.assertNotIn(TEST_TOKEN, json.dumps(report))
        self.assertNotIn("return flase;", json.dumps(report))
        self.assertEqual(report, audit_repair_get_session(
            GitHubReadOnlyClient(TEST_TOKEN, opener=RepairHTTP(data)), request))

    def test_drift_second_pass_denied_no_retry(self):
        request, data = repair_fixture()
        transport = RepairHTTP(data, drift=True)
        report = audit_repair_get_session(
            GitHubReadOnlyClient(TEST_TOKEN, opener=transport), request)
        self.assertEqual(report["decision"], "DENY")
        self.assertFalse(report["readback_structurally_consistent"])
        self.assertEqual(report["get_request_count"], 6)
        self.assertEqual(len(transport.calls), 6)
        self.assertFalse(report["retry_allowed"])

    def test_missing_or_unavailable_source_is_denied(self):
        request, data = repair_fixture()
        del data[ROOT + "/contents/" + request["path"] +
                 "?ref=" + request["expected_head_sha"]]
        transport = RepairHTTP(data)
        outcome = audit_repair_get_session(
            GitHubReadOnlyClient(TEST_TOKEN, opener=transport), request)
        self.assertEqual(outcome["decision"], "DENY")
        self.assertEqual(outcome["get_request_count"], 3)
        self.assertEqual(len(transport.calls), 3)

    def test_invalid_scope_denied_before_network(self):
        cases = (
            ("path", "private/admin.php"),
            ("path", "tests/folder/phase_c_test.php"),
            ("branch", "main"),
            ("actual_head_sha", "f" * 40),
            ("attempts", 1),
            ("author_approved", False),
        )
        for key, value in cases:
            with self.subTest(key=key):
                request, data = repair_fixture()
                request[key] = value
                transport = RepairHTTP(data)
                verdict = audit_repair_get_session(
                    GitHubReadOnlyClient(TEST_TOKEN, opener=transport), request)
                self.assertEqual(verdict["decision"], "DENY")
                self.assertEqual(verdict["get_request_count"], 0)
                self.assertEqual(transport.calls, [])

    def test_forged_readback_denied(self):
        for path_selector, mutation in (
            ("pr", lambda x: x["head"]["repo"].update(full_name="wrong/repo")),
            ("pr", lambda x: x.update(draft=False)),
            ("branch", lambda x: x["object"].update(sha="f" * 40)),
            ("blob", lambda x: x.update(sha="0" * 40)),
        ):
            request, paths = repair_fixture()
            selected = [p for p in paths if
                        ("/pulls/" in p if path_selector == "pr" else
                         "/git/ref/" in p if path_selector == "branch" else
                         "/contents/" in p)][0]
            mutation(paths[selected])
            transport = RepairHTTP(paths)
            verdict = audit_repair_get_session(
                GitHubReadOnlyClient(TEST_TOKEN, opener=transport), request)
            self.assertEqual(verdict["decision"], "DENY")
            self.assertFalse(verdict["can_write"])

    def test_unknown_client_never_reads(self):
        request, _ = repair_fixture()
        result = audit_repair_get_session(object(), request)
        self.assertEqual(result["decision"], "DENY")
        self.assertEqual(result["get_request_count"], 0)



if __name__ == "__main__":
    unittest.main()
