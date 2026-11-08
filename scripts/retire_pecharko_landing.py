#!/usr/bin/env python3
"""One-off, owner-bound removal of obsolete Pecharko landing files.

This deliberately cannot delete arbitrary paths and does not alter the
shared OVH writer or any existing releasegate. Preflight is read-only.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import sys

from scripts import shared_ovh_release as engine

ROOT = Path(__file__).resolve().parents[1]
RELEASE_ID = "retire-pecharko-landing-20261108"
TARGETS = (
    "catalogus-public/pecharko.php",
    "catalogus-public/assets/pecharko.css",
)
HASH = re.compile(r"^[0-9a-f]{64}$")
COMMIT = re.compile(r"^[0-9a-f]{40}$")
EVIDENCE = ROOT / "pecharko-retirement-evidence.json"
BACKUPS = ROOT / "pecharko-retirement-backups"


def expected_from_environment():
    values = (
        os.environ.get("EXPECTED_PECHARKO_PHP_SHA", ""),
        os.environ.get("EXPECTED_PECHARKO_CSS_SHA", ""),
    )
    if not all(HASH.fullmatch(x) for x in values):
        raise ValueError("Both exact authenticated remote SHA-256 baselines required")
    return dict(zip(TARGETS, values))


def verify_owner():
    sha = os.environ.get("GITHUB_SHA", "")
    if not COMMIT.fullmatch(sha):
        raise ValueError("Exact immutable GitHub SHA required")
    if os.environ.get("RETIRE_CONFIRMATION", "") != "DELETE-PECHARKO-LANDING-20261108-" + sha:
        raise ValueError("Owner-bound one-off deletion authorization missing")
    return sha


def collect(connect=engine.connect):
    client, base = connect()
    try:
        with client.open_sftp() as sftp:
            sentinel = engine.read_remote(sftp, base + "/catalogus-public/admin/index.php")
            if sentinel is None or b"dashboard-table" not in sentinel:
                raise RuntimeError("Incorrect pinned remote root")
            return {name: engine.read_remote(sftp, base + "/" + name) for name in TARGETS}
    finally:
        client.close()


def run_preflight(connect=engine.connect):
    observed = collect(connect)
    hashes = {name: "ABSENT" if data is None else engine.digest(data)
              for name, data in observed.items()}
    for name, value in hashes.items():
        print("READ ONLY RETIRE BASELINE", name, value)
    (ROOT / "pecharko-retirement-readonly.json").write_text(
        json.dumps({"schema": 1, "files": hashes}, indent=2) + "\n", encoding="utf-8"
    )
    return hashes


def run_deploy(connect=engine.connect):
    sha = verify_owner()
    expected = expected_from_environment()
    if BACKUPS.exists():
        raise RuntimeError("Existing retirement backup folder: STOP")
    evidence = {"schema": 1, "release_id": RELEASE_ID, "main_sha": sha,
                "status": "NOT_STARTED", "files": [], "rollback": "NOT_NEEDED"}
    client = None
    deleted = []
    originals = {}
    try:
        client, base = connect()
        with client.open_sftp() as sftp:
            sentinel = engine.read_remote(sftp, base + "/catalogus-public/admin/index.php")
            if sentinel is None or b"dashboard-table" not in sentinel:
                raise RuntimeError("Incorrect pinned remote root")
            # Read and check BOTH remote originals before touching either file.
            for name in TARGETS:
                data = engine.read_remote(sftp, base + "/" + name)
                if data is None or engine.digest(data) != expected[name]:
                    raise RuntimeError("STOP: retirement production drift: " + name)
                originals[name] = data
                print("BASELINE OK", name, expected[name])
            BACKUPS.mkdir(mode=0o700)
            for index, name in enumerate(TARGETS):
                backup = BACKUPS / ("original-" + str(index) + ".bak")
                backup.write_bytes(originals[name])
                backup.chmod(0o600)
                if engine.digest(backup.read_bytes()) != expected[name]:
                    raise RuntimeError("Local rollback backup mismatch: " + name)
            for name in TARGETS:
                sftp.remove(base + "/" + name)
                deleted.append(name)
                if engine.read_remote(sftp, base + "/" + name) is not None:
                    raise RuntimeError("Deletion readback not ABSENT: " + name)
                evidence["files"].append({"path": name,
                                          "baseline_sha256": expected[name],
                                          "readback": "ABSENT"})
                print("VERIFIED ABSENT", name)
            evidence["status"] = "VERIFIED"
    except Exception:
        if client is not None and deleted:
            evidence["rollback"] = "FAILED"
            try:
                with client.open_sftp() as sftp:
                    for name in reversed(deleted):
                        with sftp.open(base + "/" + name, "wb") as output:
                            output.write(originals[name])
                        actual = engine.read_remote(sftp, base + "/" + name)
                        if actual is None or engine.digest(actual) != expected[name]:
                            raise RuntimeError("Rollback verification failed: " + name)
                    evidence["rollback"] = "VERIFIED"
                    print("ROLLBACK VERIFIED")
            except Exception:
                evidence["status"] = "ROLLBACK_FAILED"
                raise
        evidence["status"] = "FAILED"
        raise
    finally:
        if client is not None:
            client.close()
        EVIDENCE.write_text(json.dumps(evidence, indent=2) + "\n", encoding="utf-8")


def main():
    if len(sys.argv) != 2 or sys.argv[1] not in ("preflight", "deploy"):
        raise ValueError("Usage: retire_pecharko_landing.py preflight|deploy")
    if sys.argv[1] == "preflight":
        run_preflight()
    else:
        run_deploy()


if __name__ == "__main__":
    main()
