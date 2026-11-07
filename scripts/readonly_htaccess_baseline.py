#!/usr/bin/env python3
"""Read only the exact public .htaccess file using existing pinned OVH SFTP.

Intentionally independent of shared allowlist: never makes .htaccess deployable
and never reads arbitrary remote paths. No remote write operations exist.
"""
import json
import os
import posixpath
import stat

from scripts import shared_ovh_release as engine

TARGET = "catalogus-public/.htaccess"
OUTPUT = "readonly-htaccess-baseline.json"
MAX_BYTES = 8 * 1024 * 1024


def observe(connect=engine.connect):
    client, base = connect()
    try:
        with client.open_sftp() as sftp:
            sentinel = engine.read_remote(sftp, posixpath.join(base, "catalogus-public/admin/index.php"))
            if sentinel is None or b"dashboard-table" not in sentinel:
                raise RuntimeError("Incorrect OVH root; no baseline accepted")
            remote = posixpath.join(base, TARGET)
            try:
                info = sftp.lstat(remote)
            except OSError as exc:
                if getattr(exc, "errno", None) != 2:
                    raise
                return {"path": TARGET, "state": "ABSENT", "remote_sha256": "ABSENT"}
            if not stat.S_ISREG(info.st_mode) or not 0 < info.st_size <= MAX_BYTES:
                raise RuntimeError("Remote .htaccess empty, unsafe or too large: STOP")
            with sftp.open(remote, "rb") as handle:
                raw = handle.read(MAX_BYTES + 1)
            if len(raw) != info.st_size:
                raise RuntimeError("Remote .htaccess changed during read")
            return {"path": TARGET, "state": "PRESENT", "remote_sha256": engine.digest(raw)}
    finally:
        client.close()


def main():
    row = observe()
    print("READ ONLY BASELINE", row["path"], row["state"], row["remote_sha256"])
    with open(OUTPUT, "w", encoding="utf-8") as handle:
        json.dump({"schema": 1, "kind": "read_only_protected_htaccess_baseline",
                   "commit": os.getenv("GITHUB_SHA", ""), "run_id": os.getenv("GITHUB_RUN_ID", ""),
                   "writes_performed": False, "files": [row]}, handle, indent=2)
        handle.write("\n")


if __name__ == "__main__":
    main()
