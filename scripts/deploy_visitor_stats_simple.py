#!/usr/bin/env python3
"""Visitor stats five-PHP-file release. Manual deploy WITHOUT rollback.

Safety: strict SHA, fixed allowlist, read-only remote drift check, pinned SSH
host key, upload order, post-write hashes. On any write failure, STOP and ask
for manual repair: there is NO rollback and NO automatic retry.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import sys

from plan_visitor_stats_release import FILES, plan
from preflight_visitor_stats_remote import NEW, EXISTING, inspect, path_at, read_file, info, PreflightStop
from compare_visitor_stats_drift import OBSERVED

def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()

def assert_preflight(sftp, root, manifest):
    report = inspect(sftp, root, manifest)
    if tuple(item["path"] for item in manifest["files"]) != FILES:
        raise PreflightStop("Unexpected five-file release allowlist")
    expected = {item["path"]: item["remote_sha256"] for item in report["runtime_files"] if item["path"] in EXISTING}
    if expected != OBSERVED:
        raise PreflightStop("Production drift from T-062 read-only evidence")
    if any(item.get("remote_sha256") != OBSERVED[item["path"]]
           for item in report["runtime_files"] if item["path"] in EXISTING):
        raise PreflightStop("Production drift or missing historical baseline")
    if any(item.get("remote") != "absent_as_expected"
           for item in report["runtime_files"] if item["path"] in NEW):
        raise PreflightStop("Unexpected new file already exists")
    return report

def upload_no_rollback(sftp, root, manifest, source: dict[str, bytes]):
    # Never use this helper without a separately approved manual dispatch.
    if tuple(source) != FILES:
        raise PreflightStop("Source ordering does not match exact release allowlist")
    assert_preflight(sftp, root, manifest)
    for item in manifest["files"]:
        name = item["path"]
        payload = source[name]
        if digest(payload) != item["sha256"]:
            raise PreflightStop("Source SHA256 mismatch")
    # Revalidate all remote prerequisites immediately before first write.
    assert_preflight(sftp, root, manifest)
    completed = []
    for name in FILES:
        remote_path = path_at(root, name)
        try:
            # Avoid following a pre-existing symlink and silently overwriting it.
            exists = info(sftp, root, name, optional=True)
            if name in NEW and exists is not None:
                raise PreflightStop("New target appeared: " + name)
            if name in EXISTING:
                if exists is None or digest(read_file(sftp, root, name)) != OBSERVED[name]:
                    raise PreflightStop("Existing target changed immediately before upload: " + name)
            # Simple rollout intentionally has no remote backup and no rollback.
            # Exclusive create for new files; overwrite existing code only after checks.
            mode = "wxb" if name in NEW else "wb"  # Paramiko: exclusive AND writable
            with sftp.open(remote_path, mode) as f:
                f.write(source[name])
                f.flush()
            if digest(read_file(sftp, root, name)) != digest(source[name]):
                raise PreflightStop("Post-upload SHA256 mismatch: " + name)
            completed.append(name)
            print(json.dumps({"uploaded_and_verified": name, "sha256": digest(source[name])}), flush=True)
        except Exception as exc:
            # NEVER attempt a hidden rollback. Operator must inspect production.
            raise PreflightStop("UPLOAD STOP after " + str(len(completed))
                                + " verified file(s); manual recovery required. "
                                + "Failed path: " + name) from exc
    return completed

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--release-sha", required=True)
    parser.add_argument("--mode", choices=("plan", "deploy"), required=True)
    args = parser.parse_args()
    try:
        manifest = plan(args.release_sha.lower())
        if args.mode == "plan":
            print(json.dumps({**manifest, "deployment_mode": "no_rollback",
                "privacy_and_schema_must_be_approved_separately": True,
                "upload_allowed": False}, indent=2, sort_keys=True))
            return 0
        source = {}
        from pathlib import Path
        repo = Path(__file__).resolve().parents[1]
        for name in FILES:
            p = repo / name
            if not p.is_file() or p.is_symlink():
                raise PreflightStop("Invalid source: " + name)
            source[name] = p.read_bytes()
        from deploy_ovh_sftp import connect, remote_root
        client = connect()
        try:
            with client.open_sftp() as sftp:
                upload_no_rollback(sftp, remote_root(), manifest, source)
        finally:
            client.close()
        print("Five PHP files uploaded and SHA256 verified. Manual site validation still required.")
        return 0
    except Exception:
        print("STOP: no rollback was configured. Inspect state before manual recovery.",file=sys.stderr)
        return 1

if __name__ == "__main__":
    raise SystemExit(main())
