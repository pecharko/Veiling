"""Offline tests: GitHub REST GET-only transport and safe response handling."""
import json
import unittest
from urllib.error import HTTPError

from scripts.phase_c_github_readonly_client import (
    API, ROOT, GitHubReadError, GitHubReadOnlyClient, MissingNonceRef,
    MAX_RESPONSE_BYTES,
)


class FakeResponse:
    def __init__(self, payload, status=200):
        self.status = status
        self.payload = payload

    def read(self, limit):
        return self.payload[:limit]

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc_value, traceback):
        return False


class FakeOpener:
    def __init__(self, payload=None, status=200):
        self.payload = (
            json.dumps({"full_name": "pecharko/Veiling"}).encode()
            if payload is None else payload
        )
        self.status = status
        self.calls = []

    def open(self, request, timeout):
        self.calls.append((request, timeout))
        return FakeResponse(self.payload, self.status)


class ReadOnlyClientTests(unittest.TestCase):
    def test_valid_scope_uses_only_get_and_exact_api_host(self):
        opener = FakeOpener()
        client = GitHubReadOnlyClient("local-test-token", opener=opener)
        self.assertEqual(client.get(ROOT)["full_name"], "pecharko/Veiling")
        self.assertEqual(len(opener.calls), 1)
        req, timeout = opener.calls[0]
        self.assertEqual(req.get_method(), "GET")
        self.assertEqual(req.full_url, API + ROOT)
        self.assertEqual(timeout, 10)
        self.assertEqual(req.get_header("Authorization"), "Bearer local-test-token")

    def test_authorized_endpoint_samples(self):
        paths = (
            ROOT, ROOT + "/pulls/901", ROOT + "/issues/900",
            ROOT + "/issues/comments/101",
            ROOT + "/contents/tests/phase_c_sandbox_test.php?ref=" + "a" * 40,
            ROOT + "/git/commits/" + "a" * 40,
            ROOT + "/git/trees/" + "b" * 40 + "?recursive=1",
            ROOT + "/git/blobs/" + "c" * 40,
            ROOT + "/git/ref/heads/test/phase-c-sandbox-20261029",
            ROOT + "/git/ref/tags/phase-c-once-issue-900",
        )
        opener = FakeOpener()
        client = GitHubReadOnlyClient("test", opener=opener)
        for path in paths:
            with self.subTest(path=path):
                self.assertIsInstance(client.get(path), dict)
        self.assertEqual(len(opener.calls), len(paths))
        self.assertTrue(all(req.get_method() == "GET" for req, _ in opener.calls))

    def test_untrusted_paths_never_reach_network(self):
        paths = (
            "https://evil.example/repos/pecharko/Veiling",
            "/repos/other/Veiling",
            "/repos/pecharko/Veiling/issues/900/comments",
            ROOT + "/actions/workflows/verified-deploy-controller.yml/dispatches",
            ROOT + "/contents/private/config.php?ref=" + "a" * 40,
            ROOT + "/git/refs/heads/main",
            ROOT + "/git/ref/heads/main",
            ROOT + "/git/ref/heads/test/phase-c-../main",
            ROOT + "/git/ref/tags/phase-c-once-issue-0",
            ROOT + "/git/ref/tags/phase-c-once-issue-123?x=y",
            ROOT + "/pulls/0",
            ROOT + "/pulls/901/merge",
            ROOT + "/git/blobs/" + "X" * 40,
            ROOT + "/git/trees/" + "a" * 40,
            ROOT + "%2Fgit%2Fcommits%2F" + "a" * 40,
            ROOT + "/git/blobs/" + "a" * 40 + "#redirect",
            "",
            None,
        )
        opener = FakeOpener()
        client = GitHubReadOnlyClient("test", opener=opener)
        for path in paths:
            with self.subTest(path=path):
                with self.assertRaises(GitHubReadError):
                    client.get(path)
        self.assertEqual(opener.calls, [])

    def test_only_tag_404_is_distinguished_and_never_retried(self):
        class NotFound:
            def __init__(self):
                self.calls = []
            def open(self, request, timeout):
                self.calls.append(request.full_url)
                raise HTTPError(request.full_url, 404, "not found", {}, None)

        opener = NotFound()
        client = GitHubReadOnlyClient("test", opener=opener)
        with self.assertRaises(MissingNonceRef):
            client.get(ROOT + "/git/ref/tags/phase-c-once-issue-900")
        with self.assertRaises(GitHubReadError) as denied:
            client.get(ROOT + "/git/ref/heads/test/phase-c-sandbox-20261029")
        self.assertNotIsInstance(denied.exception, MissingNonceRef)
        self.assertEqual(len(opener.calls), 2)

    def test_strict_errors_and_no_secret_in_exception(self):
        for value, status in (
            (b"[1,2]", 200),
            (b"invalid-json", 200),
            (b"{}", 302),
            (b"{}" + b" " * (MAX_RESPONSE_BYTES + 1), 200),
        ):
            with self.subTest(status=status, payload=len(value)):
                client = GitHubReadOnlyClient(
                    "super-secret-should-not-leak",
                    opener=FakeOpener(payload=value, status=status),
                )
                with self.assertRaises(GitHubReadError) as error:
                    client.get(ROOT)
                self.assertNotIn("super-secret-should-not-leak", str(error.exception))

    def test_invalid_credentials_and_timeout_denied(self):
        for token in (None, "", "  ", "line\nfeed"):
            with self.subTest(token=token):
                with self.assertRaises(ValueError):
                    GitHubReadOnlyClient(token)
        for timeout in (False, 0, 31, "ten"):
            with self.subTest(timeout=timeout):
                with self.assertRaises(ValueError):
                    GitHubReadOnlyClient("test", timeout=timeout)


if __name__ == "__main__":
    unittest.main()
