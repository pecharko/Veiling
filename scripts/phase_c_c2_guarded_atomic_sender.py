"""Phase C2 combined mutation review: no credential-bearing transport path.

Security correction: the prior private _post_once_injected accepted any callable,
including urllib.request.urlopen, which allowed a real mutation if supplied a
token. No API in this module may now send HTTP or accept an enabling opener.
The reviewed mutation remains inert data pending independent trusted execution.
"""
from scripts.phase_c_atomic_combined_review import propose_atomic_combined


def inspect_atomic_envelope(reservation, advance):
    """Offline scope review, never execution authority."""
    deny = {"decision": "DENY", "write_allowed": False,
            "network_allowed": False, "retry_allowed": False,
            "activation_allowed": False}
    review = propose_atomic_combined(reservation, advance)
    if review.get("decision") != "REVIEW_ONLY":
        return dict(deny, reason="Invalid combined mutation scope")
    return dict(deny, decision="REVIEW_ONLY",
                reason="Combined mutation validated only as inert data")


def _post_once_injected(*_args, **_kwargs):
    """Fail closed for legacy direct imports: NO token or opener use."""
    return {"decision": "DENY", "write_allowed": False,
            "network_allowed": False, "retry_allowed": False,
            "next_write_allowed": False, "operations": [],
            "reason": "Removed insecure injected HTTP writer path"}


def submit_atomic(*_args, **_kwargs):
    """Public writer entrypoint permanently denies."""
    return {"decision": "DENY", "write_allowed": False,
            "network_allowed": False, "retry_allowed": False,
            "next_write_allowed": False, "operations": []}
