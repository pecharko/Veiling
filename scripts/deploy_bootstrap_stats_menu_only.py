#!/usr/bin/env python3
"""SHA-guarded, one-file bootstrap menu correction; manual execution only."""
import argparse
import hashlib
import os
import posixpath
import stat
import sys
from pathlib import Path
from deploy_ovh_sftp import connect, remote_root

TARGET = "private/bootstrap.php"
EXPECTED_REMOTE_SHA = "e9f0b900b3bc6f58045a5cf4e464d9594854b94c94e1e31f74d728efd0955a9a"
EXPECTED_SOURCE_SHA = "02d4844e330f9f53549dd0a2c02f11e94cb8ea8ad7acd7288ff078f6b5c60179"
ADDED_VAR = b"  $stats = e(url('admin/statistieken.php'));\n"
ADDED_LINK = b'<a href="' + b"' . $stats . '" + b'">Statistieken</a>'
SOURCE = Path("private/bootstrap.php")
BACKUP = Path("bootstrap-remote-backup.bin")

def sha(content):
    return hashlib.sha256(content).hexdigest()

def source_and_baseline():
    reference = SOURCE.read_bytes()
    if sha(reference) != EXPECTED_SOURCE_SHA:
        raise RuntimeError("STOP: release source is not the pinned reviewed bootstrap version")
    if reference.count(ADDED_VAR) != 1 or reference.count(ADDED_LINK) != 1:
        raise RuntimeError("STOP: menu patch is not unique")
    baseline = reference.replace(ADDED_VAR, b"", 1).replace(ADDED_LINK, b"", 1)
    if sha(baseline) != EXPECTED_REMOTE_SHA:
        raise RuntimeError("STOP: source minus exactly the menu additions is not the approved live baseline")
    return reference, baseline

def read_remote(sftp, root):
    target = posixpath.join(root, TARGET)
    info = sftp.lstat(target)
    if not stat.S_ISREG(info.st_mode) or info.st_size != 6117:
        raise RuntimeError("STOP: unexpected bootstrap type or byte count")
    with sftp.open(target, "rb") as handle:
        data = handle.read(1000000)
    if len(data) != info.st_size or sha(data) != EXPECTED_REMOTE_SHA:
        raise RuntimeError("STOP: production drift; no write performed")
    return target, data

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=("plan", "backup", "deploy"))
    args = parser.parse_args()
    source, baseline = source_and_baseline()
    if args.mode == "plan":
        print("PASS: exact menu-only patch verified against SHA-guarded live baseline")
        print("Expected live SHA-256:", EXPECTED_REMOTE_SHA)
        print("Expected resulting SHA-256:", sha(source))
        print("No OVH access or writes in offline plan")
        return
    client = connect()
    try:
        with client.open_sftp() as sftp:
            target, original = read_remote(sftp, remote_root())
            if original != baseline:
                raise RuntimeError("STOP: actual live bytes do not match approved menu-only baseline")
            if args.mode == "backup":
                if BACKUP.exists():
                    raise RuntimeError("STOP: backup file already exists on runner")
                BACKUP.write_bytes(original)
                print("PASS: exact remote baseline saved in CI backup file")
                return
            if os.environ.get("BOOTSTRAP_MENU_CONFIRMATION") != "DEPLOY BOOTSTRAP STATISTIEKEN MENU ONLY":
                raise RuntimeError("STOP: distinct production authorization missing")
            if not BACKUP.is_file() or BACKUP.read_bytes() != original:
                raise RuntimeError("STOP: verified same-run backup missing or mismatched")
            # Restricted to one existing regular file; all other live bytes stay unchanged.
            try:
                with sftp.open(target, "wb") as handle:
                    handle.write(source)
                    handle.flush()
                with sftp.open(target, "rb") as handle:
                    after = handle.read(1000000)
                if after != source:
                    raise RuntimeError("STOP: uploaded PHP SHA mismatch")
                print("Verified remote SHA256 for private/bootstrap.php:", sha(after))
            except Exception:
                # Best effort rollback to original verified bytes, no other paths.
                try:
                    with sftp.open(target, "wb") as handle:
                        handle.write(original)
                        handle.flush()
                    with sftp.open(target, "rb") as handle:
                        restored = handle.read(1000000)
                    if restored != original:
                        raise RuntimeError("rollback checksum mismatch")
                    print("Rollback verified to original SHA-256", sha(restored), file=sys.stderr)
                except Exception as rollback_error:
                    print("CRITICAL: rollback failed; manual repair required: " + str(rollback_error), file=sys.stderr)
                raise
    finally:
        client.close()

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("RELEASE STOP: " + str(exc), file=sys.stderr)
        sys.exit(1)
