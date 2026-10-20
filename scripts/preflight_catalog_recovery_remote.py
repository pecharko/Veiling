#!/usr/bin/env python3
"""Read-only OVH SFTP preflight for exactly eight catalog recovery runtime paths.

It does NOT upload, rename, delete, chmod, write SQL, read private snapshot
contents, create any backup, clear a journal or permit deployment. It proves
ONLY that the pinned-host-key SFTP tree still matches the last independently
verified GitHub code baseline, and that no restore-in-progress markers exist.

This workflow must be manually dispatched AFTER security review and merge.
"""
from __future__ import annotations

import argparse
import errno
import json
import posixpath
import re
import stat
import sys
from pathlib import Path

from plan_catalog_recovery_release import (
    FILES, MAX_FILE_BYTES, git, sha256, source_manifest,
)

BASELINE_SHA = "8b2e6b8f444f67952620163c523e04c5807a032e"
NEW_HELPERS = frozenset((
    "private/catalog_mutex.php",
    "private/restore_journal.php",
))
EXISTING = tuple(path for path in FILES if path not in NEW_HELPERS)
REQUIRED_DIRS = (
    "private",
    "private/backups",
    "catalogus-public",
    "catalogus-public/admin",
    "catalogus-public/uploads",
)
JOURNAL_NAMES = frozenset((".restore-active.json",))


class PreflightStop(RuntimeError):
    """Safe, non-sensitive reason to stop before considering a release."""


def safe_join(root: str, relative: str) -> str:
    if not relative or relative.startswith("/") or ".." in relative.split("/"):
        raise PreflightStop("Invalid relative release path")
    return posixpath.join(root, relative)


def remote_lstat(sftp, root: str, name: str, *, optional: bool = False):
    try:
        return sftp.lstat(safe_join(root, name))
    except OSError as exc:
        if optional and exc.errno == errno.ENOENT:
            return None
        raise PreflightStop("Missing or inaccessible remote path: " + name) from exc


def remote_directory(sftp, root: str, relative: str) -> None:
    st = remote_lstat(sftp, root, relative)
    # lstat, NOT stat: SFTP symbolic links are rejected.
    if st is None or not stat.S_ISDIR(st.st_mode):
        raise PreflightStop("Not a real remote directory: " + relative)


def remote_regular_file(sftp, root: str, relative: str) -> bytes:
    st = remote_lstat(sftp, root, relative)
    if (st is None or not stat.S_ISREG(st.st_mode)
            or not 1 <= st.st_size <= MAX_FILE_BYTES):
        raise PreflightStop("Unexpected remote file type/size: " + relative)
    try:
        with sftp.open(safe_join(root, relative), "rb") as stream:
            data = stream.read(MAX_FILE_BYTES + 1)
    except OSError as exc:
        raise PreflightStop("Cannot safely read remote code: " + relative) from exc
    if len(data) != st.st_size or not 1 <= len(data) <= MAX_FILE_BYTES:
        raise PreflightStop("Remote code changed during read: " + relative)
    return data


def no_active_restore_markers(sftp, root: str) -> None:
    """Only list NAMES in private backups, never read any backup contents."""
    try:
        backups = sftp.listdir(safe_join(root, "private/backups"))
        public = sftp.listdir(safe_join(root, "catalogus-public"))
    except OSError as exc:
        raise PreflightStop("Cannot enumerate restore-marker directories") from exc
    if any(
        name in JOURNAL_NAMES
        or name.startswith(".restore-active.tmp-")
        or name.startswith(".restore-finalizing-tmp-")
        for name in backups
    ):
        raise PreflightStop("Pending restore journal/evidence marker: STOP")
    if any(
        name.startswith(".uploads-before-restore-")
        or name.startswith(".restore-")
        for name in public
    ):
        raise PreflightStop("Unfinished restore photo directory: STOP")


def verify_last_deployed_baseline(release_sha: str) -> dict[str, bytes]:
    """The six old files MUST equal immutable Git content from audit release."""
    if not re.fullmatch(r"[a-f0-9]{40}", release_sha):
        raise PreflightStop("Release commit must be a full SHA")
    try:
        git("merge-base", "--is-ancestor", BASELINE_SHA, release_sha)
    except RuntimeError as exc:
        raise PreflightStop("Expected audited baseline is not in release history") from exc
    result: dict[str, bytes] = {}
    for name in EXISTING:
        try:
            original = git("show", f"{BASELINE_SHA}:{name}")
        except RuntimeError as exc:
            raise PreflightStop("Baseline file missing in audited commit: " + name) from exc
        if not 1 <= len(original) <= MAX_FILE_BYTES:
            raise PreflightStop("Invalid baseline source length: " + name)
        result[name] = original
    if len(result) != 6 or len(NEW_HELPERS) != 2:
        raise PreflightStop("Unexpected eight-file runtime boundary")
    return result


def inspect_sftp_readonly(
    sftp, root: str, baseline: dict[str, bytes], manifest: dict[str, object]
) -> dict[str, object]:
    """All SFTP operations are lstat/open(rb)/listdir: no remote writes."""
    if not root or "\n" in root or "\r" in root:
        raise PreflightStop("Invalid configured SFTP root")
    entries = manifest["files"]
    if len(entries) != 8 or tuple(item["path"] for item in entries) != FILES:
        raise PreflightStop("Offline release manifest does not cover exact allowlist")
    if set(baseline) != set(EXISTING):
        raise PreflightStop("Missing approved historical baseline paths")

    for folder in REQUIRED_DIRS:
        remote_directory(sftp, root, folder)

    # Confirm project identity even if OVH_SFTP_BASE points at a wrong tree.
    bootstrap = remote_regular_file(sftp, root, "private/bootstrap.php")
    admin = remote_regular_file(sftp, root, "catalogus-public/admin/index.php")
    if b"function admin_top(" not in bootstrap or b"Kaveloverzicht" not in admin:
        raise PreflightStop("Remote root does not match the expected 41 Lokeren app")

    no_active_restore_markers(sftp, root)
    observed = []
    for item in entries:
        name = item["path"]
        remote_stat = remote_lstat(sftp, root, name, optional=name in NEW_HELPERS)
        if name in NEW_HELPERS:
            if remote_stat is not None:
                raise PreflightStop("New dependency unexpectedly exists on remote: " + name)
            observed.append({
                "path": name, "current_remote": "absent_as_expected",
                "planned_sha256": item["sha256"],
            })
            continue
        if remote_stat is None or not stat.S_ISREG(remote_stat.st_mode):
            raise PreflightStop("Existing remote code is not a regular file: " + name)
        actual = remote_regular_file(sftp, root, name)
        if actual != baseline[name]:
            raise PreflightStop("PRODUCTION DRIFT; source differed from audited baseline: " + name)
        observed.append({
            "path": name,
            "current_remote": "verified_audit_baseline",
            "remote_sha256": sha256(actual),
            "planned_sha256": item["sha256"],
        })

    # Read everything again to detect remote changes DURING the check.
    no_active_restore_markers(sftp, root)
    for name in FILES:
        st = remote_lstat(sftp, root, name, optional=name in NEW_HELPERS)
        if name in NEW_HELPERS:
            if st is not None:
                raise PreflightStop("Unexpected helper appeared during preflight: " + name)
        else:
            if st is None or not stat.S_ISREG(st.st_mode):
                raise PreflightStop("Existing remote code vanished: " + name)
            if remote_regular_file(sftp, root, name) != baseline[name]:
                raise PreflightStop("Remote changed while checking: " + name)

    return {
        "format": "41lokeren-recovery-remote-readonly-preflight-v1",
        "release_sha": manifest["release_sha"],
        "last_confirmed_production_release_sha": BASELINE_SHA,
        "source_manifest_sha256": manifest["source_manifest_sha256"],
        "sftp_root_identifier_sha256": sha256(root.encode("utf-8")),
        "status": "read_only_baseline_verified",
        "runtime_files": observed,
        "runtime_count": len(observed),
        "existing_files_at_audited_baseline": len(EXISTING),
        "new_dependencies_absent": len(NEW_HELPERS),
        "recovery_markers_absent_at_check": True,
        "host_key_pinned_by_existing_sftp_client": True,
        "remote_writes_performed": False,
        "live_snapshot_contents_read": False,
        "database_access_performed": False,
        "code_backup_created": False,
        "external_data_backup_verified": False,
        "runtime_and_mysql_compatibility_verified": False,
        "admin_quiet_window_confirmed": False,
        "release_approved": False,
        "deploy_approved": False,
        "upload_allowed": False,
        "next_gate": "manual review of preflight, offline rollback plan, separate code/data backups, maintenance window and explicit release consent",
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--release-sha", required=True)
    opts = parser.parse_args()
    try:
        approved_sha = opts.release_sha.lower()
        manifest = source_manifest(approved_sha)
        baseline = verify_last_deployed_baseline(approved_sha)
        # Import only when manually invoking the real remote check.
        from deploy_ovh_sftp import connect, remote_root
        root = remote_root()
        client = connect()  # Exact pinned known_hosts, RejectPolicy, port 22.
        try:
            with client.open_sftp() as sftp:
                report = inspect_sftp_readonly(sftp, root, baseline, manifest)
        finally:
            client.close()
        print(json.dumps(report, sort_keys=True, indent=2))
        return 0
    except PreflightStop as exc:
        print("READ-ONLY REMOTE PREFLIGHT STOP: " + str(exc), file=sys.stderr)
        return 1
    except Exception:
        # Do not expose authentication errors, root paths or private messages.
        print("READ-ONLY REMOTE PREFLIGHT FAILED. Keep production untouched.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
