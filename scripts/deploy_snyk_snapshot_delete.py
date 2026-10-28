#!/usr/bin/env python3
"""Exact two-file protected snapshot release; independent read-only preflight."""
import hashlib
import os
import posixpath
import re
import sys
from pathlib import Path
from scripts import shared_ovh_release as engine

RELEASE_ID = "snyk-snapshot-delete-20261028"
FILES = ("private/snapshots.php", "catalogus-public/admin/backup.php")
SHA = re.compile(r"^[a-f0-9]{64}$")
COMMIT = re.compile(r"^[a-f0-9]{40}$")


def source():
    data = {}
    for name in FILES:
        p = engine.ROOT / name
        if p.is_symlink() or not p.is_file() or not 0 < p.stat().st_size <= engine.MAX_FILE:
            raise ValueError("Invalid protected source: " + name)
        data[name] = p.read_bytes()
    return data


def preflight():
    data = source()
    client, base = engine.connect()
    try:
        with client.open_sftp() as sftp:
            marker = engine.read_remote(sftp, posixpath.join(base, "catalogus-public/admin/index.php"))
            if marker is None or b"dashboard-table" not in marker:
                raise ValueError("Incorrect production root")
            for name in FILES:
                existing = engine.read_remote(sftp, posixpath.join(base, name))
                if existing is None:
                    raise ValueError("Existing protected file missing: " + name)
                print("SOURCE", name, engine.digest(data[name]))
                print("REMOTE", name, engine.digest(existing))
            print("READ ONLY: no production changes")
    finally:
        client.close()


def guarded_load(release_id, *, source_root=None):
    if release_id != RELEASE_ID or source_root is not None:
        raise ValueError("Invalid protected release request")
    data = source()
    expected = {
        FILES[0]: os.environ.get("EXPECTED_SNAPSHOT_SHA", ""),
        FILES[1]: os.environ.get("EXPECTED_BACKUP_SHA", ""),
    }
    if any(not SHA.fullmatch(v) for v in expected.values()):
        raise ValueError("Both independently approved remote hashes required")
    items = [{"path": name, "source_sha256": engine.digest(data[name]),
              "remote_sha256": expected[name]} for name in FILES]
    return {"schema": 1, "files": items}, data, expected


def main():
    if len(sys.argv) != 2 or sys.argv[1] not in ("plan", "preflight", "deploy"):
        raise ValueError("Usage: plan|preflight|deploy")
    mode = sys.argv[1]
    if mode == "preflight":
        preflight()
        return
    if mode == "plan":
        for name, raw in source().items():
            print("SOURCE", name, engine.digest(raw))
        print("PLAN ONLY")
        return
    sha = os.environ.get("GITHUB_SHA", "")
    confirmation = os.environ.get("DEPLOY_CONFIRMATION", "")
    if not COMMIT.fullmatch(sha) or confirmation != "DEPLOY-" + RELEASE_ID + "-" + sha:
        raise ValueError("Exact immutable authorization missing")
    scripts = str(engine.ROOT / "scripts")
    if scripts not in sys.path:
        sys.path.insert(0, scripts)
    engine.load_manifest = guarded_load
    sys.argv = ["shared_ovh_release.py", "deploy", RELEASE_ID]
    engine.run()


if __name__ == "__main__":
    main()
