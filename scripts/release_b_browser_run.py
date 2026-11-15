#!/usr/bin/env python3
"""Explicit external Playwright runner for public-only post-release checks.

No stored sessions, admin credentials, screenshots, traces, recordings or user
data. Never scheduled or invoked from a deployment workflow.
"""
import json
import sys
from release_b_public_browser import browser_check

def main():
    if len(sys.argv) != 5:
        raise ValueError("Usage: release_b_browser_run.py release-id release-sha run-id session-id")
    try:
        run_id = int(sys.argv[3])
    except ValueError as exc:
        raise ValueError("Invalid run id") from exc
    from playwright.sync_api import sync_playwright
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        try:
            context = browser.new_context(accept_downloads=False, service_workers="block")
            try:
                page = context.new_page()
                result = browser_check(page, release_id=sys.argv[1],
                                       release_sha=sys.argv[2], run_id=run_id,
                                       session_id=sys.argv[4])
            finally:
                context.close()
        finally:
            browser.close()
    print(json.dumps(result, sort_keys=True))
    if result["status"] != "PASS":
        sys.exit(1)

if __name__ == "__main__":
    main()
