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
    "804429448": ("website", "https://41lokeren.be/"),
    "804429483": ("admin", "https://41lokeren.be/admin/login.php"),
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

def url_issue(url):
    if not isinstance(url, str):
        return "missing_url"
    try:
        parts = urlsplit(url)
        if parts.hostname != "41lokeren.be":
            return "unexpected_host"
        if parts.scheme not in ("http", "https"):
            return "unexpected_scheme"
        if parts.username or parts.password or parts.port or parts.query or parts.fragment:
            return "unexpected_url_components"
        return "unexpected_path"
    except ValueError:
        return "invalid_url"

def verify(payload):
    if not isinstance(payload, dict) or not isinstance(payload.get("data"), list):
        raise ValueError("API data must be an array")
    if payload.get("nextLink"):
        raise ValueError("Pagination detected")
    found = {}
    for m in payload["data"]:
        if not isinstance(m, dict):
            raise ValueError("Malformed monitor")
        ident = str(m.get("id", ""))
        if ident not in EXPECTED:
            continue
        role, expected_url = EXPECTED[ident]
        if ident in found:
            raise ValueError("Duplicate expected monitor ID: " + role)
        raw = m.get("url")
        if canonical(raw) != expected_url:
            raise ValueError("Expected monitor ID " + role + " has unexpected URL format: " + url_issue(raw))
        if m.get("status") != "UP":
            raise ValueError("Monitor " + role + " is not UP")
        found[ident] = {"id": ident, "status": "UP", "role": role}
    if set(found) != set(EXPECTED):
        raise ValueError("Missing required monitor ID(s): " + ",".join(EXPECTED[k][0] for k in EXPECTED if k not in found))
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
    # Schema discovery only: disclose field NAMES and types, not values.
    # Do not mistake the API fetch time for the last independent HTTP probe.
    known = {"804429448", "804429483"}
    metadata = {}
    for m in payload["data"]:
        if str(m.get("id")) not in known:
            continue
        keys = sorted(k for k in m if isinstance(k, str)
                      and any(term in k.lower() for term in ("check", "time", "date", "last", "interval", "region", "incident")))
        metadata[str(m["id"])] = {k: type(m[k]).__name__ for k in keys}
    evidence["freshness_schema_fields_only"] = metadata
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
