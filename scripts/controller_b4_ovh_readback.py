#!/usr/bin/env python3
"""Opt-in, standalone OVH SFTP READ-ONLY source-hash verifier (B4).

Never run in release/deploy workflow. No upload, delete, rename, mkdir or
secret printing. Output is an observation, not deployment/browser acceptance.
"""
import argparse
import json
import posixpath
import re
from shared_ovh_release import connect, digest, load_manifest, read_remote

SHA = re.compile(r"^[a-f0-9]{40}$")


def verify(release_id, release_sha, run_id, *, read=None):
    """Read bounded exact manifest paths; no acceptance verdict if incomplete."""
    if (not isinstance(release_sha, str) or not SHA.fullmatch(release_sha)
            or type(run_id) is not int or run_id <= 0):
        raise ValueError("Invalid immutable release identity")
    manifest, payload, _baselines = load_manifest(release_id)
    # The manifest is loaded from a pinned checkout by the caller; this
    # function cannot prove the checkout SHA and must not claim that it does.
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
            "independent_execution_authenticated": False,
            "browser_acceptance": "PENDING"}


def main():
    parser = argparse.ArgumentParser(description="Standalone read-only OVH observation; no writes")
    parser.add_argument("--release-id", required=True)
    parser.add_argument("--release-sha", required=True)
    parser.add_argument("--run-id", type=int, required=True)
    args = parser.parse_args()
    result = verify(args.release_id, args.release_sha, args.run_id)
    print(json.dumps(result, indent=2, sort_keys=True))
    return 0 if result["status"] == "OBSERVED_MATCH" else 1


if __name__ == "__main__":
    raise SystemExit(main())
