#!/usr/bin/env python3
"""Offline cross-evidence contract, never a production authorization or release closeout.

All evidence is untrusted input. Matching fields cannot establish that a readback
was independently acquired or that a browser was really exercised. A reviewer
must separately authenticate each origin before a release can be closed.
"""
import json
import re
import sys
from ovh_deploy_evidence_contract import validate as validate_upload

SHA = re.compile(r"^[0-9a-f]{40}$")
DIGEST = re.compile(r"^[0-9a-f]{64}$")
RELEASE = re.compile(r"^[a-z0-9][a-z0-9-]{2,60}$")

def fields(obj, required):
    if not isinstance(obj, dict) or set(obj) != set(required):
        raise ValueError("Unexpected evidence fields")

def independent_readback_shape(readback):
    fields(readback, ("schema", "release_id", "release_sha", "run_id", "origin",
                      "collection_id", "files", "status"))
    if type(readback["schema"]) is not int or readback["schema"] != 1:
        raise ValueError("Unsupported independent readback schema")
    if readback["origin"] != "separate-read-only-sftp-collector":
        raise ValueError("Uploader logs are not independent evidence")
    if (not isinstance(readback["collection_id"], str) or
            not re.fullmatch(r"[a-z0-9][a-z0-9-]{7,80}", readback["collection_id"])):
        raise ValueError("Invalid external collection id")
    if readback["status"] != "COLLECTED":
        raise ValueError("Readback was not collected")
    entries = readback["files"]
    if not isinstance(entries, list) or not 1 <= len(entries) <= 25:
        raise ValueError("Invalid readback rows")
    observed = {}
    for item in entries:
        fields(item, ("path", "remote_sha256"))
        path = item["path"]
        if (not isinstance(path, str) or
                not path.startswith(("catalogus-public/", "private/")) or
                any(part in ("", ".", "..") or part.startswith(".") for part in path.split("/")) or
                path in observed):
            raise ValueError("Unsafe or duplicate readback path")
        digest = item["remote_sha256"]
        if not isinstance(digest, str) or not DIGEST.fullmatch(digest):
            raise ValueError("Invalid remote digest")
        observed[path] = digest
    return observed

def browser_shape(browser):
    fields(browser, ("schema", "release_id", "release_sha", "run_id",
                     "origin", "session_id", "status", "checks"))
    if type(browser["schema"]) is not int or browser["schema"] != 1:
        raise ValueError("Unsupported browser schema")
    if browser["origin"] != "trusted-external-browser":
        raise ValueError("Unknown browser origin")
    if (not isinstance(browser["session_id"], str) or
            not re.fullmatch(r"[a-z0-9][a-z0-9-]{7,80}", browser["session_id"])):
        raise ValueError("Invalid browser session")
    if browser["status"] not in ("PASS", "FAIL"):
        raise ValueError("Unsupported browser result")
    checks = browser["checks"]
    if not isinstance(checks, list) or not checks or len(checks) > 30 or (
            any(not isinstance(check, str) or
                not re.fullmatch(r"[a-z][a-z0-9-]{2,60}", check) for check in checks)
            or len(set(checks)) != len(checks)):
        raise ValueError("Malformed browser check list")
    return browser["status"] == "PASS"

def reconcile(upload, independent, browser):
    upload = validate_upload(upload)
    if upload["status"] != "VERIFIED":
        raise ValueError("Uploader did not report verified release")
    independent_files = independent_readback_shape(independent)
    browser_passed = browser_shape(browser)
    keys = ("release_id", "release_sha", "run_id")
    for source in (independent, browser):
        for key in keys:
            if source[key] != upload[key] or type(source[key]) != type(upload[key]):
                raise ValueError("Release identity mismatch")
    if not isinstance(upload["release_id"], str) or not RELEASE.fullmatch(upload["release_id"]):
        raise ValueError("Invalid release id")
    if not isinstance(upload["release_sha"], str) or not SHA.fullmatch(upload["release_sha"]):
        raise ValueError("Invalid release SHA")
    if type(upload["run_id"]) is not int or upload["run_id"] <= 0:
        raise ValueError("Invalid run id")
    expected = {item["path"]: item["source_sha256"] for item in upload["files"]}
    if expected != independent_files:
        raise ValueError("Independent readback differs from expected file hashes")
    return {
        "release_id": upload["release_id"],
        "release_sha": upload["release_sha"],
        "run_id": upload["run_id"],
        "files_correlated": len(expected),
        "readback_claim_matches_uploader": True,
        "browser_result_claim": "PASS" if browser_passed else "FAIL",
        "source_authenticity": "NOT_ESTABLISHED_BY_THIS_CONTRACT",
        "independent_origin_authenticated": False,
        "browser_origin_authenticated": False,
        "release_administration": "OPEN",
        "status": "REVIEW_EXTERNAL_ORIGINS" if browser_passed else "BROWSER_REJECTED",
    }

def main():
    payload = json.load(sys.stdin)
    fields(payload, ("uploader", "independent_readback", "browser"))
    print(json.dumps(reconcile(payload["uploader"], payload["independent_readback"],
                               payload["browser"]), sort_keys=True))

if __name__ == "__main__":
    main()
