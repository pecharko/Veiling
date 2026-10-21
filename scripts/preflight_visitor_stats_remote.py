#!/usr/bin/env python3
"""T-062 read-only SFTP inventory for the statistics release.

Only lstat, listdir, and open(rb). No uploads, renames, removal, chmod or SQL.
Reports the three existing runtime hashes for independent drift review.
"""
from __future__ import annotations

import argparse
import errno
import hashlib
import json
import posixpath
import stat
import sys

from plan_visitor_stats_release import FILES, plan

NEW = frozenset(("private/visitor_stats.php", "catalogus-public/admin/statistieken.php"))
EXISTING = frozenset(FILES) - NEW
DIRS = ("private", "private/backups", "catalogus-public", "catalogus-public/admin", "catalogus-public/uploads")
MAX_BYTES = 1024 * 1024

class PreflightStop(RuntimeError):
    pass

def path_at(root, relative):
    if not root or not root.startswith("/") or any(c in root for c in "\r\n"):
        raise PreflightStop("Untrusted SFTP root")
    if not relative or relative.startswith("/") or ".." in relative.split("/"):
        raise PreflightStop("Unsafe path")
    return posixpath.join(root, relative)

def info(sftp, root, name, optional=False):
    try:
        return sftp.lstat(path_at(root, name))
    except OSError as exc:
        if optional and exc.errno == errno.ENOENT:
            return None
        raise PreflightStop("Missing remote path: " + name) from exc

def read_file(sftp, root, name):
    st = info(sftp, root, name)
    if not stat.S_ISREG(st.st_mode) or not 1 <= st.st_size <= MAX_BYTES:
        raise PreflightStop("Unsafe remote file type or length: " + name)
    with sftp.open(path_at(root, name), "rb") as stream:
        data = stream.read(MAX_BYTES + 1)
    if len(data) != st.st_size:
        raise PreflightStop("Remote file changed during read: " + name)
    return data

def restore_markers_clear(sftp, root):
    try:
        backups = sftp.listdir(path_at(root, "private/backups"))
        public = sftp.listdir(path_at(root, "catalogus-public"))
    except OSError as exc:
        raise PreflightStop("Cannot list maintenance marker locations") from exc
    if any(x == ".restore-active.json" or x.startswith((".restore-active.tmp-", ".restore-finalizing-tmp-")) for x in backups):
        raise PreflightStop("Active restore marker: STOP")
    if any(x.startswith((".uploads-before-restore-", ".restore-")) for x in public):
        raise PreflightStop("Active photo restoration: STOP")

def inspect(sftp, root, manifest):
    if not stat.S_ISDIR(sftp.lstat(root).st_mode):
        raise PreflightStop("Untrusted root: not a real directory")
    if tuple(x["path"] for x in manifest["files"]) != FILES:
        raise PreflightStop("Unexpected release allowlist")
    for directory in DIRS:
        if not stat.S_ISDIR(info(sftp, root, directory).st_mode):
            raise PreflightStop("Not a real directory: " + directory)
    bootstrap = read_file(sftp, root, "private/bootstrap.php")
    dashboard = read_file(sftp, root, "catalogus-public/admin/index.php")
    if b"function admin_top(" not in bootstrap or b"Kaveloverzicht" not in dashboard:
        raise PreflightStop("Wrong catalog application root")
    restore_markers_clear(sftp, root)
    result = []
    first = {}
    for item in manifest["files"]:
        name = item["path"]
        remote = info(sftp, root, name, optional=name in NEW)
        if name in NEW:
            if remote is not None:
                raise PreflightStop("Expected new file already exists: " + name)
            result.append({"path":name,"remote":"absent_as_expected","planned_sha256":item["sha256"]})
        else:
            data = read_file(sftp, root, name)
            digest = hashlib.sha256(data).hexdigest()
            first[name] = digest
            result.append({"path":name,"remote":"existing","remote_sha256":digest,"planned_sha256":item["sha256"],"baseline_verified":False})
    restore_markers_clear(sftp, root)
    for name in FILES:
        if name in NEW:
            if info(sftp, root, name, optional=True) is not None:
                raise PreflightStop("New file appeared during inspection")
        else:
            if hashlib.sha256(read_file(sftp, root, name)).hexdigest() != first[name]:
                raise PreflightStop("Remote changed during inspection: " + name)
    return {
        "format":"visitor-stats-readonly-inventory-v1",
        "release_sha":manifest["release_sha"],
        "runtime_files":result,
        "expected_new_files_absent":True,
        "root_verified_by_application_markers":True,
        "restore_markers_absent_at_check":True,
        "remote_baseline_verified":False,
        "requires_manual_drift_review":True,
        "remote_writes_performed":False,
        "database_access_performed":False,
        "code_backup_created":False,
        "upload_allowed":False,
        "deploy_approved":False,
    }

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--release-sha",required=True)
    args = parser.parse_args()
    try:
        manifest = plan(args.release_sha.lower())
        from deploy_ovh_sftp import connect, remote_root
        root = remote_root()
        client = connect()
        try:
            with client.open_sftp() as sftp:
                result = inspect(sftp, root, manifest)
        finally:
            client.close()
        print(json.dumps(result, indent=2, sort_keys=True))
        return 0
    except PreflightStop as exc:
        print("READ-ONLY STOP: "+str(exc),file=sys.stderr)
        return 1
    except Exception:
        print("READ-ONLY FAILED; no production changes made.",file=sys.stderr)
        return 1

if __name__=="__main__":
    raise SystemExit(main())
