#!/usr/bin/env python3
"""Opt-in, standalone OVH SFTP READ-ONLY source-hash verifier (B4).

Never run in release/deploy workflow. No upload, delete, rename, mkdir or
secret printing. Output is an observation, not deployment/browser acceptance.
"""
import argparse
import json
import os
import posixpath
import re
import subprocess
from pathlib import Path
from shared_ovh_release import ROOT, connect, digest, load_manifest, read_remote
from controller_evidence_collector import get_json
from controller_run_evidence_binding import inspect_run

SHA = re.compile(r"^[a-f0-9]{40}$")


def verify_release_run(run_id, release_sha, token, *, read_json=get_json):
    """Require real GitHub successful shared-release job before any OVH read.

    inspect_run deliberately returns INCOMPLETE even on success because
    GitHub metadata is not independent OVH proof. Accept only its exact
    positive-observation reason, never a generic CI-success claim.
    """
    if (type(run_id) is not int or run_id <= 0
            or not isinstance(release_sha, str) or not SHA.fullmatch(release_sha)
            or not isinstance(token, str) or not token):
        raise ValueError("Invalid GitHub release evidence identity")
    run = read_json("/actions/runs/" + str(run_id), token)
    page = read_json("/actions/runs/" + str(run_id) + "/jobs?per_page=100", token)
    if (not isinstance(page, dict)
            or type(page.get("total_count")) is not int
            or page["total_count"] <= 0 or page["total_count"] >= 100
            or not isinstance(page.get("jobs"), list)
            or len(page["jobs"]) != page["total_count"]):
        raise ValueError("Unbounded or incomplete release jobs")
    decision = inspect_run(run, page["jobs"], run_id=run_id,
                           release_sha=release_sha)
    if (decision.get("status") != "INCOMPLETE"
            or decision.get("reason") !=
            "actions_success_not_independent_remote_proof"):
        raise ValueError("GitHub release-run identity or job not verified")
    return decision


def check_checkout(source_root=ROOT):
    """Fail closed unless the Git checkout is clean and points to one commit."""
    head = subprocess.run(["git", "rev-parse", "--verify", "HEAD"],
                          cwd=source_root, check=True, capture_output=True,
                          text=True, timeout=10).stdout.strip()
    dirty = subprocess.run(["git", "status", "--porcelain", "--untracked-files=all"],
                           cwd=ROOT, check=True, capture_output=True,
                           text=True, timeout=10).stdout
    if not SHA.fullmatch(head) or dirty:
        raise ValueError("OVH readback requires a clean pinned Git checkout")
    return head


def verify(release_id, release_sha, run_id, *, read=None, checkout_check=check_checkout, source_root=None):
    """Read bounded exact manifest paths; no acceptance verdict if incomplete."""
    if (not isinstance(release_sha, str) or not SHA.fullmatch(release_sha)
            or type(run_id) is not int or run_id <= 0):
        raise ValueError("Invalid immutable release identity")
    # Check commit and working tree before opening an OVH connection.
    if (checkout_check() if source_root is None else checkout_check(source_root)) != release_sha:
        raise ValueError("Refusing unpinned OVH manifest checkout")
    manifest, payload, _baselines = (load_manifest(release_id) if source_root is None else
                                     load_manifest(release_id, source_root=source_root))
    rows = []
    def check(reader):
        for entry in manifest["files"]:
            name = entry["path"]
            actual = reader(name)
            observed = "ABSENT" if actual is None else digest(actual)
            target_sha = entry["source_sha256"]
            rows.append({"path": name, "expected_sha256": target_sha,
                         "observed_sha256": observed,
                         "match": observed == target_sha})
    if read is not None:
        check(read)
    else:
        client, base = connect()
        try:
            with client.open_sftp() as sftp:
                sentinel = read_remote(sftp, posixpath.join(base, "catalogus-public/admin/index.php"))
                if sentinel is None or b"dashboard-table" not in sentinel:
                    raise ValueError("Wrong OVH remote root")
                check(lambda path: read_remote(sftp, posixpath.join(base, path)))
        finally:
            client.close()
    success = bool(rows) and all(r["match"] for r in rows)
    return {"schema": 1, "source": "separate_read_only_ovh_sftp_session",
            "release_id": release_id, "release_sha": release_sha, "run_id": run_id,
            "status": "OBSERVED_MATCH" if success else "MISMATCH",
            "files": rows, "writes_performed": False,
            "checkout_sha_verified": True,
            "independent_execution_authenticated": False,
            "browser_acceptance": "PENDING"}


def main():
    parser = argparse.ArgumentParser(description="Standalone read-only OVH observation; no writes")
    parser.add_argument("--release-id", required=True)
    parser.add_argument("--release-sha", required=True)
    parser.add_argument("--run-id", type=int, required=True)
    parser.add_argument("--release-checkout", default=None)
    args = parser.parse_args()
    # GITHUB_TOKEN needs only Actions:read. No remote contact without proof.
    verify_release_run(args.run_id, args.release_sha, os.environ.get("GITHUB_TOKEN", ""))
    release_root = Path(args.release_checkout).resolve() if args.release_checkout else None
    if release_root is not None and (not release_root.is_dir() or release_root == ROOT):
        raise ValueError("Separate immutable release data checkout required")
    result = verify(args.release_id, args.release_sha, args.run_id, source_root=release_root)
    print(json.dumps(result, indent=2, sort_keys=True))
    return 0 if result["status"] == "OBSERVED_MATCH" else 1


if __name__ == "__main__":
    raise SystemExit(main())
