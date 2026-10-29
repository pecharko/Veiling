"""Phase C2 reviewed transport primitive — no live activation route.

One POST GraphQL transport implementation, usable only by a future trusted
caller after independent authorization. The public entrypoint always denies.
The internal single-shot function is intentionally restricted to an injected
test opener and has no default HTTP client, token loader, environment flag,
retry, or CI integration. Treat the internal function as security-sensitive.
"""
import json
import urllib.request

from scripts.phase_c_atomic_combined_review import propose_atomic_combined

URL = "https://api.github.com/graphql"
MAX_BYTES = 65536


def _post_once_injected(reservation, advance, token, *, opener):
    """Hermetic transport contract for an injected opener; never opens sockets itself."""
    denied = {"decision": "DENY", "write_allowed": False,
              "retry_allowed": False, "next_write_allowed": False}
    if not callable(opener) or type(token) is not str or not token or any(
            c in token for c in "\r\n"):
        return dict(denied, reason="Missing isolated sender inputs")
    plan = propose_atomic_combined(reservation, advance)
    if plan.get("decision") != "REVIEW_ONLY":
        return dict(denied, reason="Ref scope failed")
    request = urllib.request.Request(
        URL,
        data=json.dumps(plan["envelope"], sort_keys=True, separators=(",", ":")).encode(),
        method="POST",
        headers={"Authorization": "Bearer " + token,
                 "Accept": "application/vnd.github+json",
                 "Content-Type": "application/json",
                 "X-GitHub-Api-Version": "2022-11-28"},
    )
    try:
        with opener(request, timeout=10) as response:
            body = response.read(MAX_BYTES + 1)
        if len(body) > MAX_BYTES:
            return dict(denied, decision="AMBIGUOUS", reason="Oversized mutation response")
        payload = json.loads(body)
    except Exception:
        # Unknown network outcomes cannot be retried safely.
        return dict(denied, decision="AMBIGUOUS", reason="Transport outcome unknown")
    if type(payload) is not dict or "errors" in payload:
        return dict(denied, decision="AMBIGUOUS", reason="GraphQL outcome uncertain")
    data = payload.get("data")
    result = data.get("updateRefs") if type(data) is dict else None
    expected = plan["envelope"]["variables"]["input"]["clientMutationId"]
    if (type(result) is not dict or set(result) != {"clientMutationId"}
            or result.get("clientMutationId") != expected):
        return dict(denied, decision="AMBIGUOUS", reason="Acknowledgement not verified")
    return dict(denied, decision="ACK_NEEDS_READBACK",
                reason="Acknowledgement is not independently verified mutation evidence")


def submit_atomic(*_args, **_kwargs):
    """The only intended application entrypoint always refuses writes."""
    return {"decision": "DENY", "write_allowed": False,
            "retry_allowed": False, "next_write_allowed": False,
            "network_allowed": False, "operations": []}
