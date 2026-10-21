#!/usr/bin/env python3
"""Manual security-gated OVH SFTP analytics_secret setup.

Inspect is read-only. Install only on separately authorized workflow_dispatch;
key exists solely in runner memory and remote private/config.php, never stdout,
GitHub Secrets, logs, artifacts, or repository. Fail closed on unexpected config.
"""
from __future__ import annotations
import argparse
import hashlib
import os
import posixpath
import re
import secrets
import stat
import subprocess
import sys
import tempfile

from preflight_visitor_stats_remote import (
    PreflightStop, path_at, read_file, restore_markers_clear,
)
CONFIG = "private/config.php"
MAX_CONFIG_BYTES = 256 * 1024

class SafeStageFailure(RuntimeError):
    """Only a fixed, nonsecret stage identifier is allowed in logs."""

    def __init__(self, stage: str):
        self.stage = stage
        super().__init__("Stopped at safe stage: " + stage)


def pending_temp_count(sftp, root):
    """Count leftovers without disclosing filenames or reading secret material."""
    entries = sftp.listdir(path_at(root, "private"))
    return sum(name.startswith(".config-analytics-") and name.endswith(".tmp")
               for name in entries)


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()

def read_private_config(sftp, root):
    st = sftp.lstat(path_at(root, CONFIG))
    if not stat.S_ISREG(st.st_mode) or not 1 <= st.st_size <= MAX_CONFIG_BYTES:
        raise PreflightStop("Private config missing, symlink, or unsafe size")
    data = read_file(sftp, root, CONFIG)
    if not data.startswith(b"<?php") or b"analytics_secret" in data:
        raise PreflightStop("Unexpected config format or analytics_secret already present")
    return data, st.st_mode & 0o777

def verify_root(sftp, root):
    if not stat.S_ISDIR(sftp.lstat(root).st_mode):
        raise PreflightStop("Wrong SFTP root")
    for name, marker in (
        ("private/bootstrap.php", b"function admin_top("),
        ("catalogus-public/admin/index.php", b"Kaveloverzicht"),
    ):
        if marker not in read_file(sftp, root, name):
            raise PreflightStop("Application identity mismatch")
    restore_markers_clear(sftp, root)

def patch_config(data: bytes, secret: str) -> bytes:
    if len(secret) != 64 or not re.fullmatch(r"[a-f0-9]{64}", secret):
        raise PreflightStop("Invalid generated key")
    if b"analytics_secret" in data:
        raise PreflightStop("Secret already exists: do not rotate")
    # Deliberately support only literal PHP return-array configs; no guesswork.
    match = re.search(rb"\breturn\s*\[", data)
    if not match or len(re.findall(rb"\breturn\s*\[", data)) != 1:
        raise PreflightStop("Unsafe PHP config shape; manual review required")
    # Require the ending close of the same array. No silent rewrite/reformat.
    if not re.search(rb"\]\s*;\s*(?:\?>\s*)?$", data):
        raise PreflightStop("Unsupported config ending")
    insertion = b"\n  'analytics_secret' => '" + secret.encode("ascii") + b"',"
    return data[:match.end()] + insertion + data[match.end():]

def php_lint(payload: bytes) -> None:
    # Never print PHP source, stdout or stderr: config contains credentials.
    with tempfile.TemporaryDirectory(prefix="config-lint-") as folder:
        path = os.path.join(folder, "config.php")
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "wb") as handle:
            handle.write(payload)
        run = subprocess.run(["php", "-l", path], capture_output=True, check=False, timeout=15)
        if run.returncode:
            raise PreflightStop("PHP config lint failed, content withheld")

def inspect(sftp, root):
    verify_root(sftp, root)
    original, mode = read_private_config(sftp, root)
    php_lint(original)
    verify_root(sftp, root)
    again, _ = read_private_config(sftp, root)
    if original != again:
        raise PreflightStop("Config changed while inspecting")
    return sha(original), mode

def install(sftp, root, expected_sha: str, token: str) -> None:
    if not re.fullmatch(r"[a-f0-9]{64}", expected_sha):
        raise PreflightStop("Expected baseline digest required")
    existing_hash, mode = inspect(sftp, root)
    if existing_hash != expected_sha:
        raise PreflightStop("Config drift since separately approved inspect")
    original, _ = read_private_config(sftp, root)
    if sha(original) != expected_sha:
        raise PreflightStop("Config changed after preflight")
    secret = secrets.token_hex(32)
    updated = patch_config(original, secret)
    php_lint(updated)
    restore_markers_clear(sftp, root)
    if sha(read_private_config(sftp, root)[0]) != expected_sha:
        raise PreflightStop("Config drift immediately before install")
    remote = path_at(root, CONFIG)
    temporary = path_at(root, "private/.config-analytics-" + token + ".tmp")
    # Failures are reported by fixed stage ONLY; never echo exception text,
    # config contents, credentials, secret, or remote directory listing.
    created = False
    stage = "create-temp"
    try:
        with sftp.open(temporary, "x") as handle:
            created = True
            stage = "set-private-permissions"
            sftp.chmod(temporary, 0o600)
            stage = "write-temp"
            handle.write(updated)
            handle.flush()
        stage = "validate-temp"
        st = sftp.lstat(temporary)
        if not stat.S_ISREG(st.st_mode) or st.st_size != len(updated):
            raise PreflightStop("Temp file validation failed")
        with sftp.open(temporary, "rb") as handle:
            if sha(handle.read(MAX_CONFIG_BYTES+1)) != sha(updated):
                raise PreflightStop("Temp checksum failed")
        stage = "check-drift"
        if sha(read_private_config(sftp, root)[0]) != expected_sha:
            raise PreflightStop("Config changed before atomic rename")
        restore_markers_clear(sftp, root)
        stage = "atomic-replace"
        sftp.posix_rename(temporary, remote)
        created = False
        stage = "verify-final"
        with sftp.open(remote, "rb") as handle:
            actual = handle.read(MAX_CONFIG_BYTES + 1)
        if actual != updated:
            raise PreflightStop("Post-install content mismatch; operator intervention required")
        print("Configuration updated and verified; secret value withheld.")
    except Exception:
        # No error interpolation: SFTP exceptions may contain sensitive paths.
        raise SafeStageFailure(stage) from None
    finally:
        if created:
            try:
                sftp.remove(temporary)
            except OSError:
                print("STOP: temporary private file may remain; inspect manually.", file=sys.stderr)

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--mode", choices=("inspect","install"), required=True)
    parser.add_argument("--expected-sha", default="")
    parser.add_argument("--run-id", default="")
    args = parser.parse_args()
    try:
        from deploy_ovh_sftp import remote_root, connect
        root = remote_root()
        client = connect()
        try:
            with client.open_sftp() as sftp:
                if args.mode == "inspect":
                    digest, _ = inspect(sftp, root)
                    leftover = pending_temp_count(sftp, root)
                    print("Read-only pending temp count: " + str(leftover))
                    print("READ-ONLY config status: analytics_secret absent; PHP syntax valid.")
                    print("Config SHA256 baseline: " + digest)
                    print("No remote writes; no secret generated.")
                else:
                    if not args.run_id.isdecimal():
                        raise PreflightStop("GitHub run ID required")
                    install(sftp, root, args.expected_sha, args.run_id)
        finally:
            client.close()
        return 0
    except SafeStageFailure as exc:
        print("STOP: install failed at stage " + exc.stage + "; remote state must be checked. No secret disclosed.", file=sys.stderr)
        return 1
    except Exception:
        print("STOP: analytics config validation failed before final write, or inspection failed. Remote state must be checked. No secret disclosed.", file=sys.stderr)
        return 1

if __name__=="__main__":
    sys.exit(main())
