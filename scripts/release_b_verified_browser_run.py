#!/usr/bin/env python3
"""Explicit, separately invoked browser acceptance probe using GitHub API GETs.

Not wired to CI, owner approval, deployment or any scheduler. The operator
controls where Chromium runs. This provides browser *observation* but cannot
by itself authenticate independence of that operator or close a release.
"""
import json
import re
import sys

from release_b_github_browser_preflight import collect_verified_browser_inputs
from release_b_public_browser import browser_check, SESSION


def prepare_browser_probe(run_id, session_id, fetch=None):
    if not isinstance(session_id, str) or not SESSION.fullmatch(session_id):
        raise ValueError("Invalid externally assigned browser session id")
    if fetch is None:
        proof = collect_verified_browser_inputs(run_id)
    else:
        proof = collect_verified_browser_inputs(run_id, fetch)
    identity = {
        "release_id": proof["release_id"],
        "release_sha": proof["release_sha"],
        "run_id": proof["run_id"],
        "session_id": session_id,
    }
    return proof, identity


def format_probe_report(proof, result):
    if result["status"] not in ("PASS", "FAIL"):
        raise ValueError("Invalid browser result")
    if (proof["run_id"] != result["run_id"]
            or proof["release_id"] != result["release_id"]
            or proof["release_sha"] != result["release_sha"]):
        raise ValueError("Browser result does not belong to prepared run")
    return {
        "schema": 1,
        "release_id": proof["release_id"],
        "release_sha": proof["release_sha"],
        "run_id": proof["run_id"],
        "github_artifact_id": proof["github_artifact_id"],
        "github_provenance": proof["github_provenance"],
        "browser_observation": result["status"],
        "browser_checks": result["checks"],
        "browser_session_id": result["session_id"],
        "browser_origin_authenticated": False,
        "independent_ovh_readback": "PENDING",
        "release_administration": "OPEN",
    }


def main():
    if len(sys.argv) != 3:
        raise ValueError(
            "Usage: release_b_verified_browser_run.py github-deployment-run-id browser-session-id"
        )
    try:
        run_id = int(sys.argv[1])
    except ValueError as exc:
        raise ValueError("Invalid run number") from exc
    proof, identity = prepare_browser_probe(run_id, sys.argv[2])
    # All GitHub API checks have completed before Playwright is imported.
    from playwright.sync_api import sync_playwright
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        try:
            context = browser.new_context(
                accept_downloads=False,
                service_workers="block",
                ignore_https_errors=False,
            )
            try:
                result = browser_check(context.new_page(), **identity)
            finally:
                context.close()
        finally:
            browser.close()
    report = format_probe_report(proof, result)
    print(json.dumps(report, sort_keys=True))
    if result["status"] != "PASS":
        sys.exit(1)


if __name__ == "__main__":
    main()
