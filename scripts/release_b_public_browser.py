#!/usr/bin/env python3
"""Public browser smoke-test core for a separately trusted browser runner.

No production runner is configured here. Caller supplies a Playwright page and
immutable run identity. No admin credentials, storage state, screenshots or cookies
are exported. A check is PASS only when real rendered public page assertions pass.
"""
import re

SHA = re.compile(r"^[a-f0-9]{40}$")
RELEASE = re.compile(r"^[a-z0-9][a-z0-9-]{2,60}$")
SESSION = re.compile(r"^[a-z0-9][a-z0-9-]{7,80}$")
ORIGIN = "https://41lokeren.be"

def browser_check(page, *, release_id, release_sha, run_id, session_id):
    if not isinstance(release_id, str) or not RELEASE.fullmatch(release_id):
        raise ValueError("Invalid release id")
    if not isinstance(release_sha, str) or not SHA.fullmatch(release_sha):
        raise ValueError("Invalid release commit")
    if type(run_id) is not int or run_id <= 0:
        raise ValueError("Invalid release run")
    if not isinstance(session_id, str) or not SESSION.fullmatch(session_id):
        raise ValueError("Invalid browser session id")
    # No credentials: only two explicitly allowlisted public GET pages.
    checks = ("public-catalog", "artist-gallery")
    outcome = "PASS"
    for suffix, heading in (("/", "41 LOKEREN"), ("/kunstenaars.php", "Ontmoet onze kunstenaars")):
        try:
            response = page.goto(ORIGIN + suffix, wait_until="domcontentloaded", timeout=15000)
            if response is None or response.status != 200:
                outcome = "FAIL"
                break
            if not page.get_by_text(heading, exact=False).first.is_visible(timeout=5000):
                outcome = "FAIL"
                break
            if page.url != ORIGIN + suffix:
                outcome = "FAIL"
                break
        except Exception:
            outcome = "FAIL"
            break
    return {
        "schema": 1, "release_id": release_id, "release_sha": release_sha,
        "run_id": run_id, "origin": "trusted-external-browser",
        "session_id": session_id, "status": outcome, "checks": list(checks),
    }
