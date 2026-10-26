#!/usr/bin/env python3
"""Read-only UptimeRobot v3 evidence; never authorizes production deployment."""
import json
import os
import sys
import urllib.request
import urllib.error
from urllib.parse import urlsplit
from datetime import datetime, timezone

ENDPOINT = "https://api.uptimerobot.com/v3/monitors"
EXPECTED = {
    "https://41lokeren.be/": "website",
    "https://41lokeren.be/admin/login.php": "admin",
}

def canonical(url):
    if not isinstance(url, str):
        return None
    parts = urlsplit(url)
    if parts.scheme not in ("http", "https") or parts.hostname != "41lokeren.be" or parts.username or parts.password or parts.port or parts.query or parts.fragment:
        return None
    path = parts.path or "/"
    if path not in ("/", "/admin/login.php"):
        return None
    return "https://41lokeren.be" + path

def verify(payload):
    if not isinstance(payload, dict) or not isinstance(payload.get("data"), list):
        raise ValueError("API data must be an array")
    if payload.get("nextLink"):
        raise ValueError("Pagination detected; refusing incomplete monitor list")
    found = {}
    observed_paths = []
    for m in payload["data"]:
        if not isinstance(m, dict):
            raise ValueError("Malformed monitor")
        url = canonical(m.get("url"))
        if url is None:
            observed_paths.append("other")
            continue
        observed_paths.append(EXPECTED[url])
        if url in found:
            raise ValueError("Duplicate monitor for " + EXPECTED[url])
        monitor_id = m.get("id")
        if not isinstance(monitor_id, (int, str)) or not str(monitor_id).isdigit():
            raise ValueError("Missing valid monitor ID")
        status = m.get("status")
        if status != "UP":
            raise ValueError("Monitor " + EXPECTED[url] + " is not UP: " + str(status))
        found[url] = {"id": str(monitor_id), "status": status, "role": EXPECTED[url]}
    if set(found) != set(EXPECTED):
        raise ValueError("Missing required monitor(s); matched roles: " + ",".join(sorted(set(observed_paths))))
    return {"checked_at_utc": datetime.now(timezone.utc).isoformat(),
            "source": "UptimeRobot v3 snapshot",
            "fresh_measurement_proven": False,
            "release_gate_satisfied": False,
            "monitors": found}

def main():
    token = os.environ.get("UPTIMEROBOT_API_KEY", "").strip()
    if not token:
        raise ValueError("Missing UPTIMEROBOT_API_KEY GitHub secret")
    if any(c in token for c in "\r\n"):
        raise ValueError("Invalid API key")
    req = urllib.request.Request(ENDPOINT + "?limit=200",
        headers={"Authorization": "Bearer " + token, "Accept": "application/json"})
    with urllib.request.urlopen(req, timeout=20) as response:
        if response.status != 200:
            raise ValueError("Unexpected API status")
        payload = json.load(response)
    evidence = verify(payload)
    path = os.environ.get("EVIDENCE_PATH", "uptimerobot-evidence.json")
    with open(path, "x", encoding="utf-8") as handle:
        json.dump(evidence, handle, indent=2)
        handle.write("\n")
    print("Read-only UptimeRobot API succeeded: both URLs report UP.")
    print("Not a fresh-interval SLA proof and NOT a deployment authorization.")

if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError, urllib.error.URLError, json.JSONDecodeError) as exc:
        print("UPTIMEROBOT EVIDENCE FAILED: " + str(exc), file=sys.stderr)
        sys.exit(1)
