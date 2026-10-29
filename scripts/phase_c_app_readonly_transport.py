"""Strict read-only GitHub App API transport; disabled by default.

No token minting, persistence, mutation methods or workflow dispatch.
Callers provide a short-lived installation token locally, never in CI logs.
"""
import json
import urllib.error
import urllib.request

from scripts.phase_c_app_receipt import inspect_receipt

BASE = "https://api.github.com"
INSTALLATION_ID = 176547610
ALLOWED_PATHS = (
    "/installation/repositories",
)


def read_endpoint(path, token, *, enabled=False, opener=None):
    """Read only two constant GitHub endpoints; deny all other input."""
    deny = {"decision": "DENY", "write_allowed": False, "retry_allowed": False}
    if enabled is not True:
        return dict(deny, reason="Read-only transport disabled")
    if path not in ALLOWED_PATHS or type(path) is not str:
        return dict(deny, reason="Endpoint not permitted")
    if type(token) is not str or not token or "\n" in token or "\r" in token:
        return dict(deny, reason="No valid ephemeral token")
    request = urllib.request.Request(
        BASE + path,
        headers={"Authorization": "Bearer " + token,
                 "Accept": "application/vnd.github+json",
                 "X-GitHub-Api-Version": "2022-11-28"},
        method="GET",
    )
    try:
        response = (opener or urllib.request.urlopen)(request, timeout=10)
        with response:
            payload = json.loads(response.read(1024 * 1024 + 1))
        if type(payload) is not dict:
            return dict(deny, reason="Unexpected response type")
        return dict(deny, reason="Read-only response obtained",
                    read_only_payload=payload)
    except (urllib.error.URLError, OSError, ValueError, TypeError, OverflowError):
        return dict(deny, reason="Read-only request failed")


def verify_installation_receipt(installation, repositories):
    """Offline validation never grants writer permission."""
    return inspect_receipt(installation, repositories)
