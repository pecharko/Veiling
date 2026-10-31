"""Authenticated GraphQL read-only transport for C2 preparation.

Only an exact non-mutating query is accepted. An ephemeral installation token
is supplied by the local caller; never mint, persist or log it. Disabled by
default. Mutation documents cannot be submitted through this API.
"""
import json
import urllib.request
import urllib.error

URL = "https://api.github.com/graphql"
READ_QUERY = "query PhaseCReadRepository { repository(owner: \"pecharko\", name: \"Veiling\") { id nameWithOwner } }"


def fetch_repository(token, *, enabled=False, opener=None):
    deny = {"decision": "DENY", "write_allowed": False,
            "retry_allowed": False, "mutation_allowed": False}
    if enabled is not True:
        return dict(deny, reason="Authenticated GraphQL read disabled")
    if type(token) is not str or not token or any(ch in token for ch in "\r\n"):
        return dict(deny, reason="Invalid ephemeral token")
    request = urllib.request.Request(
        URL, data=json.dumps({"query": READ_QUERY}).encode("utf-8"),
        headers={"Authorization": "Bearer " + token,
                 "Accept": "application/vnd.github+json",
                 "Content-Type": "application/json",
                 "X-GitHub-Api-Version": "2022-11-28"},
        method="POST")
    try:
        response = (opener or urllib.request.urlopen)(request, timeout=10)
        with response:
            raw = response.read(65537)
        if len(raw) > 65536:
            return dict(deny, reason="Oversized response")
        payload = json.loads(raw)
        if type(payload) is not dict or "errors" in payload or set(payload) - {"data", "extensions"}:
            return dict(deny, reason="Invalid GraphQL read response")
        data = payload.get("data")
        if type(data) is not dict or set(data) != {"repository"}:
            return dict(deny, reason="Invalid response data")
        repository = data["repository"]
        if (type(repository) is not dict or
                repository.get("nameWithOwner") != "pecharko/Veiling" or
                repository.get("id") != "R_kgDOVNLxvg"):
            return dict(deny, reason="Repository identity mismatch")
        return dict(deny, decision="READ_ONLY_VERIFIED", repository_id=repository["id"],
                    reason="Authenticated read only; no writer authority")
    except (urllib.error.URLError, OSError, ValueError, TypeError, OverflowError):
        return dict(deny, reason="Read-only request failed")


def submit_mutation(*_args, **_kwargs):
    return {"decision": "DENY", "write_allowed": False,
            "retry_allowed": False, "mutation_allowed": False}
