"""Strict GitHub REST GET-only client for Phase C evidence (not activated).

No token is created or loaded from environment. The caller must explicitly
provide a credential; this code has no POST/PATCH/PUT/DELETE, GraphQL endpoint,
ref mutation, retry loop, or workflow invocation. The module is not called by
GitHub Actions: this is an inert adapter for future separately approved use.
"""
import json
import re
from urllib.error import HTTPError, URLError
from urllib.request import (
    HTTPRedirectHandler, Request, build_opener,
)

API = "https://api.github.com"
ROOT = "/repos/pecharko/Veiling"
TARGET = "tests/phase_c_sandbox_test.php"
MAX_RESPONSE_BYTES = 8 * 1024 * 1024

ID = r"[1-9][0-9]*"
SHA = r"[a-f0-9]{40}"
BRANCH = r"test/phase-c-[a-z0-9-]{1,40}"
PATH_PATTERNS = tuple(re.compile(p) for p in (
    re.escape(ROOT),
    re.escape(ROOT) + r"/pulls/" + ID,
    re.escape(ROOT) + r"/issues/" + ID,
    re.escape(ROOT) + r"/issues/comments/" + ID,
    re.escape(ROOT + "/contents/" + TARGET) + r"\?ref=" + SHA,
    re.escape(ROOT + "/contents/tests/") + r"[A-Za-z0-9_-]+_test\.php\?ref=" + SHA,
    re.escape(ROOT) + r"/git/commits/" + SHA,
    re.escape(ROOT) + r"/git/trees/" + SHA + r"\?recursive=1",
    re.escape(ROOT) + r"/git/blobs/" + SHA,
    re.escape(ROOT) + r"/git/ref/heads/main",
    re.escape(ROOT) + r"/git/ref/heads/" + BRANCH,
    re.escape(ROOT) + r"/git/ref/tags/phase-c-once-issue-" + ID,
))
NONCE_PATTERN = re.compile(
    re.escape(ROOT) + r"/git/ref/tags/phase-c-once-issue-" + ID
)


class GitHubReadError(OSError):
    """A failed, untrusted or unsupported read; caller must fail closed."""


class MissingNonceRef(GitHubReadError):
    """Authenticated GitHub REST 404 for ONLY an allowlisted nonce tag."""


class _RejectRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


def _allowed(path):
    return isinstance(path, str) and any(p.fullmatch(path) for p in PATH_PATTERNS)


class GitHubReadOnlyClient:
    """Allowlisted GitHub API GET client; no other HTTP methods exposed."""

    def __init__(self, token, opener=None, timeout=10):
        if not isinstance(token, str) or not token.strip() or any(
                ch in token for ch in "\r\n"):
            raise ValueError("Credential is required")
        if type(timeout) not in (int, float) or not 1 <= timeout <= 30:
            raise ValueError("Invalid network timeout")
        self._token = token
        self._timeout = timeout
        self._opener = opener if opener is not None else build_opener(
            _RejectRedirect()
        )

    def get(self, path):
        if not _allowed(path):
            raise GitHubReadError("GitHub API path not allowlisted")
        headers = {
            "Authorization": "Bearer " + self._token,
            "Accept": "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28",
            "User-Agent": "Veiling-phase-c-readonly",
        }
        request = Request(API + path, headers=headers, method="GET")
        try:
            with self._opener.open(request, timeout=self._timeout) as response:
                if response.status != 200:
                    raise GitHubReadError("Unexpected GitHub status")
                blob = response.read(MAX_RESPONSE_BYTES + 1)
            if not isinstance(blob, bytes) or len(blob) > MAX_RESPONSE_BYTES:
                raise GitHubReadError("Oversize or invalid GitHub response")
            result = json.loads(blob.decode("utf-8"))
            if not isinstance(result, dict):
                raise GitHubReadError("Malformed GitHub JSON response")
            return result
        except HTTPError as exc:
            # No other 404 is proof of absence; never silently retry.
            if exc.code == 404 and NONCE_PATTERN.fullmatch(path):
                raise MissingNonceRef("Owner nonce ref absent (GET 404)") from None
            raise GitHubReadError("GitHub API request rejected") from None
        except (URLError, TimeoutError, ValueError, UnicodeError,
                json.JSONDecodeError, OSError):
            raise GitHubReadError("GitHub API read or decoding failed") from None
