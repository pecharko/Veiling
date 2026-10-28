#!/usr/bin/env python3
"""One-file OVH mail-test retirement. Never invoked by existing release workflows."""
import hashlib
import json
import os
from pathlib import Path
import posixpath
import sys
from scripts.shared_ovh_release import connect, read_remote

TARGET = "catalogus-public/admin/mailtest-eenmalig.php"
EXPECTED_SHA = "4bed0df92fcafed8b24dcbf713d229260cfd27f6f923ba49b286334f5d103285"
RELEASE_ID = "ovh-mailtest-retirement-20261028"


def sha(data):
    return hashlib.sha256(data).hexdigest()


def validate_authorization(mode, main_sha, confirmation):
    if mode not in ("plan", "delete"):
        raise ValueError("Only plan or delete are supported")
    if mode == "delete":
        if not main_sha or len(main_sha) != 40 or any(c not in "0123456789abcdef" for c in main_sha):
            raise ValueError("Immutable main SHA required")
        if confirmation != "DELETE-" + RELEASE_ID + "-" + main_sha:
            raise ValueError("Exact independent delete authorization missing")


def main():
    if len(sys.argv) != 2:
        raise ValueError("Usage: python -m scripts.retire_ovh_mailtest plan|delete")
    mode = sys.argv[1]
    validate_authorization(mode, os.environ.get("GITHUB_SHA", ""),
                           os.environ.get("DELETE_CONFIRMATION", ""))
    evidence = {"release_id": RELEASE_ID, "target": TARGET,
                "expected_sha256": EXPECTED_SHA, "status": "NOT_STARTED"}
    client, base = connect()
    try:
        with client.open_sftp() as sftp:
            sentinel = read_remote(sftp, posixpath.join(base, "catalogus-public/admin/index.php"))
            if sentinel is None or b"dashboard-table" not in sentinel:
                raise RuntimeError("Incorrect OVH remote root")
            target = posixpath.join(base, TARGET)
            original = read_remote(sftp, target)
            if original is None or sha(original) != EXPECTED_SHA:
                raise RuntimeError("Remote baseline drift or missing target; refusing deletion")
            evidence["observed_sha256"] = sha(original)
            if mode == "plan":
                evidence["status"] = "PLANNED"
                print(json.dumps(evidence, sort_keys=True))
                return
            backup = Path("deployment-backups") / (RELEASE_ID + "-original.php")
            backup.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            with backup.open("xb") as handle:
                handle.write(original)
            backup.chmod(0o600)
            if backup.read_bytes() != original:
                raise RuntimeError("Backup verification failed")
            evidence["backup_path"] = str(backup)
            # Re-check after backup to close the drift window.
            if read_remote(sftp, target) != original:
                raise RuntimeError("Remote changed before deletion")
            try:
                sftp.remove(target)
                if read_remote(sftp, target) is not None:
                    raise RuntimeError("Remote deletion not verified")
            except Exception:
                if read_remote(sftp, target) is None:
                    with sftp.open(target, "xb") as handle:
                        handle.write(original)
                        handle.flush()
                if read_remote(sftp, target) != original:
                    evidence["status"] = "MANUAL_RESTORE_REQUIRED"
                    print(json.dumps(evidence, sort_keys=True), file=sys.stderr)
                    raise RuntimeError("Rollback verification failed")
                evidence["status"] = "ROLLED_BACK"
                print(json.dumps(evidence, sort_keys=True), file=sys.stderr)
                raise
            evidence["status"] = "DELETED_VERIFIED"
            print(json.dumps(evidence, sort_keys=True))
    finally:
        client.close()


if __name__ == "__main__":
    main()
