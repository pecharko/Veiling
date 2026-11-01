#!/usr/bin/env python3
"""Default-disabled offline owner-bound Shared OVH dispatch review.

No credentials, GitHub API client, network calls, workflow dispatch, or mutation.
Caller must independently authenticate owner, CI, manifest and run history;
untrusted JSON is NEVER sufficient to issue a dispatch.
"""
import hashlib
import json
import re
from pathlib import Path

SHA = re.compile(r"^[0-9a-f]{40}$")
HASH = re.compile(r"^[0-9a-f]{64}$")
RID = re.compile(r"^[a-z0-9][a-z0-9-]{2,60}$")
DENY = {"decision": "DENY", "dispatch_allowed": False, "write_allowed": False,
        "retry_allowed": False, "network_allowed": False}


def review(ticket, observed):
    """Review immutable scope and evidence; NEVER confer execution authority."""
    try:
        if type(ticket) is not dict or type(observed) is not dict:
            raise ValueError("Invalid input")
        required = {"schema", "repository", "release_id", "main_sha", "manifest_sha256",
                    "owner", "nonce", "approved_scope"}
        if set(ticket) != required or ticket["schema"] != 1:
            raise ValueError("Ticket schema")
        if ticket["repository"] != "pecharko/Veiling" or ticket["owner"] != "pecharko":
            raise ValueError("Owner or repo mismatch")
        rid, sha, manifest_sha = (ticket[k] for k in ("release_id", "main_sha", "manifest_sha256"))
        if not (type(rid) is str and RID.fullmatch(rid)
                and type(sha) is str and SHA.fullmatch(sha)
                and type(manifest_sha) is str and HASH.fullmatch(manifest_sha)):
            raise ValueError("Invalid immutable identity")
        nonce = ticket["nonce"]
        if type(nonce) is not str or not re.fullmatch(r"[0-9a-f]{64}", nonce):
            raise ValueError("Nonce must be independent random 32-byte hex")
        paths = ticket["approved_scope"]
        if type(paths) is not list or not 1 <= len(paths) <= 25 or len(paths) != len(set(paths)):
            raise ValueError("Invalid scope")
        if any(type(x) is not str or not x.startswith(("catalogus-public/", "private/"))
               or ".." in x or "\\" in x or x.startswith("private/snapshots") for x in paths):
            raise ValueError("Unsafe scope")
        expected = {"main_sha", "manifest_sha256", "release_id", "approved_scope",
                    "owner_verified", "ci_verified", "manifest_verified",
                    "baseline_verified", "nonce_unused", "no_prior_dispatch",
                    "no_concurrent_deployment", "main_current"}
        if set(observed) != expected:
            raise ValueError("Evidence schema")
        if any(observed[k] is not True for k in (
                "owner_verified", "ci_verified", "manifest_verified", "baseline_verified",
                "nonce_unused", "no_prior_dispatch", "no_concurrent_deployment",
                "main_current")):
            raise ValueError("Unverified gate")
        if any(observed[k] != ticket[k] for k in
               ("main_sha", "manifest_sha256", "release_id", "approved_scope")):
            raise ValueError("Evidence does not bind exact scope and SHA")
        # This is a format suggestion only. A future live adapter must authenticate
        # identity and atomically consume a nonce BEFORE even one dispatch attempt.
        return {**DENY, "decision": "READY_FOR_SEPARATE_OWNER_REVIEW",
                "release_id": rid, "main_sha": sha,
                "confirmation": "DEPLOY-" + rid + "-" + sha,
                "reason": "Offline consistency only; no authenticated single-use authorization"}
    except (ValueError, KeyError, TypeError):
        return {**DENY, "reason": "Invalid or incomplete owner-bound dispatch evidence"}


def execute(*_args, **_kwargs):
    return {**DENY, "reason": "Dispatch integration dormant; activation is separately authorized"}


def main():
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("ticket", type=Path)
    parser.add_argument("evidence", type=Path)
    args = parser.parse_args()
    print(json.dumps(review(json.loads(args.ticket.read_text()),
                            json.loads(args.evidence.read_text())), indent=2))


if __name__ == "__main__":
    main()
