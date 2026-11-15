#!/usr/bin/env python3
"""Explicit trusted external Playwright browser runner.

Not scheduled, not production-enabled, no admin login or stored browser state.
All input documents MUST be obtained through authenticated GitHub APIs by an
independently reviewed caller. JSON content alone is not provenance.
"""
import json
import sys
from pathlib import Path

from release_b_public_browser import browser_check, preflight_browser_run

MAX_RUN_JSON = 65536
MAX_EVIDENCE_JSON = 131072


def load_bounded_json(filename, max_size):
    path = Path(filename)
    if path.is_symlink() or not path.is_file():
        raise ValueError("Invalid input document")
    size = path.stat().st_size
    if not 0 < size <= max_size:
        raise ValueError("Missing or oversized input document")
    with path.open("rb") as stream:
        contents = stream.read(max_size + 1)
    if not 0 < len(contents) <= max_size:
        raise ValueError("Invalid input document")
    return json.loads(contents)


def plan_browser_run(run, evidence, session_id):
    identity = preflight_browser_run(run, evidence)
    if not isinstance(session_id, str) or not session_id:
        raise ValueError("Missing external browser session id")
    return {**identity, "session_id": session_id}


def main():
    if len(sys.argv) != 4:
        raise ValueError(
            "Usage: release_b_browser_run.py authenticated-run.json uploader-evidence.json browser-session-id"
        )
    run = load_bounded_json(sys.argv[1], MAX_RUN_JSON)
    evidence = load_bounded_json(sys.argv[2], MAX_EVIDENCE_JSON)
    planned = plan_browser_run(run, evidence, sys.argv[3])
    # The run is checked BEFORE Playwright/browser launch or website traffic.
    from playwright.sync_api import sync_playwright
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        try:
            context = browser.new_context(
                accept_downloads=False, service_workers="block",
                ignore_https_errors=False,
            )
            try:
                page = context.new_page()
                result = browser_check(page, **planned)
            finally:
                context.close()
        finally:
            browser.close()
    print(json.dumps(result, sort_keys=True))
    if result["status"] != "PASS":
        sys.exit(1)


if __name__ == "__main__":
    main()
