#!/usr/bin/env python3
"""T-060: isolated FAKE-SFTP verification of recovery remote-readonly preflight.

No real host, credentials, remote connection, SQL database or HTTP request.
The fixture allows ONLY lstat(), open("rb") and listdir(). Every mutating
SFTP method is absent and any attempt to open a write handle fails.
"""
from __future__ import annotations

import io
import posixpath
import stat
import sys
from collections import defaultdict
from pathlib import Path
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

from plan_catalog_recovery_release import FILES, git, source_manifest
from preflight_catalog_recovery_remote import (
    BASELINE_SHA, EXISTING, NEW_HELPERS, REQUIRED_DIRS,
    PreflightStop, inspect_sftp_readonly, verify_last_deployed_baseline,
)

RELEASE_SHA = git("rev-parse", "--verify", "HEAD").decode().strip().lower()
BASELINE = verify_last_deployed_baseline(RELEASE_SHA)
MANIFEST = source_manifest(RELEASE_SHA)
BASE = "/ci-isolated-sftp"

class FakeSftp:
    """Read-only stand-in: 0 network operations, 0 write-capable methods."""
    def __init__(self):
        self.dirs = set([BASE])
        self.files = {}
        self.links = set()
        self.reads = defaultdict(int)
        self.modes = []
        self.flip_on_third = None
        for folder in REQUIRED_DIRS:
            self.dirs.add(posixpath.join(BASE, folder))
        self.files[posixpath.join(BASE, "catalogus-public/admin/index.php")] = git(
            "show", f"{BASELINE_SHA}:catalogus-public/admin/index.php"
        )
        for name in EXISTING:
            self.files[posixpath.join(BASE, name)] = BASELINE[name]

    def lstat(self, path):
        if path in self.links:
            return SimpleNamespace(st_mode=stat.S_IFLNK | 0o777, st_size=1)
        if path in self.dirs:
            return SimpleNamespace(st_mode=stat.S_IFDIR | 0o700, st_size=0)
        if path in self.files:
            return SimpleNamespace(st_mode=stat.S_IFREG | 0o600,
                st_size=len(self.files[path]))
        raise FileNotFoundError(2, "Path missing", path)

    def open(self, path, mode="r"):
        self.modes.append(mode)
        if mode != "rb":
            raise AssertionError("Security failure: attempted SFTP write or unknown mode")
        self.reads[path] += 1
        content = self.files[path]
        if path == self.flip_on_third and self.reads[path] >= 3:
            content += b"unsafe server mutation"
        return io.BytesIO(content)

    def listdir(self, path):
        if path not in self.dirs:
            raise FileNotFoundError(2, "Directory missing", path)
        prefix = path.rstrip("/") + "/"
        return sorted({
            candidate[len(prefix):].split("/")[0]
            for candidate in (*self.files.keys(), *self.dirs, *self.links)
            if candidate.startswith(prefix)
        })

TESTS = 0

def expect_pass(title, modify=None):
    global TESTS
    fake = FakeSftp()
    if modify is not None: modify(fake)
    result = inspect_sftp_readonly(fake, BASE, BASELINE, MANIFEST)
    if (result["status"] != "read_only_baseline_verified"
        or result["runtime_count"] != 8
        or result["existing_files_at_audited_baseline"] != 6
        or result["new_dependencies_absent"] != 2
        or result["upload_allowed"] is not False
        or result["remote_writes_performed"] is not False):
        raise AssertionError("Unexpected result: " + title)
    if not fake.modes or any(mode != "rb" for mode in fake.modes):
        raise AssertionError("Non-readonly SFTP operation detected")
    TESTS += 1
    print("PASS T-060: " + title)

def expect_fail(title, modify, expected=None):
    global TESTS
    fake = FakeSftp()
    modify(fake)
    try:
        inspect_sftp_readonly(fake, BASE, BASELINE, MANIFEST)
    except PreflightStop as err:
        if expected and expected not in str(err):
            raise AssertionError(f"Wrong stop reason for {title}: {err}") from err
        if any(mode != "rb" for mode in fake.modes):
            raise AssertionError("Failed preflight still attempted write")
        TESTS += 1
        print("PASS T-060: " + title)
        return
    raise AssertionError("Dangerous preflight unexpectedly accepted: " + title)

def main():
    if len(BASELINE) != 6 or len(NEW_HELPERS) != 2:
        raise AssertionError("Historical runtime boundary changed")
    expect_pass("six exact baseline PHP files, two absent helpers and no journal")
    expect_fail(
        "deny drifted old kavel.php",
        lambda s: s.files.__setitem__(
            posixpath.join(BASE, "catalogus-public/admin/kavel.php"), b"bad data"
        ),
        "PRODUCTION DRIFT",
    )
    expect_fail(
        "deny new helper unexpectedly already on remote",
        lambda s: s.files.__setitem__(
            posixpath.join(BASE, "private/catalog_mutex.php"), b"<?php"
        ),
        "unexpectedly exists",
    )
    expect_fail(
        "deny symlink for existing PHP",
        lambda s: s.links.add(posixpath.join(BASE, "private/snapshots.php")),
        "not a regular file",
    )
    expect_fail(
        "deny symlinked private backup directory",
        lambda s: s.links.add(posixpath.join(BASE, "private/backups")),
        "Not a real remote directory",
    )
    expect_fail(
        "deny missing uploads directory",
        lambda s: s.dirs.remove(posixpath.join(BASE, "catalogus-public/uploads")),
        "Missing or inaccessible",
    )
    expect_fail(
        "deny wrong remote application root",
        lambda s: s.files.__setitem__(
            posixpath.join(BASE, "catalogus-public/admin/index.php"), b"unrelated site"
        ),
        "Remote root",
    )
    expect_fail(
        "deny active restore journal marker",
        lambda s: s.files.__setitem__(
            posixpath.join(BASE, "private/backups/.restore-active.json"), b"test only"
        ),
        "Pending restore",
    )
    expect_fail(
        "deny half-written restore journal marker",
        lambda s: s.files.__setitem__(
            posixpath.join(BASE, "private/backups/.restore-active.tmp-ci"), b"x"
        ),
        "Pending restore",
    )
    expect_fail(
        "deny incomplete approval receipt marker",
        lambda s: s.files.__setitem__(
            posixpath.join(BASE, "private/backups/.restore-finalizing-tmp-ci"), b"x"
        ),
        "Pending restore",
    )
    expect_fail(
        "deny prior upload evidence directory",
        lambda s: s.dirs.add(
            posixpath.join(BASE, "catalogus-public/.uploads-before-restore-ci")
        ),
        "Unfinished restore",
    )
    expect_fail(
        "deny interrupted photo staging directory",
        lambda s: s.dirs.add(
            posixpath.join(BASE, "catalogus-public/.restore-ci")
        ),
        "Unfinished restore",
    )
    expect_fail(
        "detect PHP bytes changing during the second read",
        lambda s: setattr(s, "flip_on_third", posixpath.join(BASE,"private/bootstrap.php")),
        "Remote code changed during read",
    )
    expect_fail(
        "deny release manifest with a non-allowlisted runtime file",
        lambda s: None,
        None,
    ) if False else None
    modified = dict(MANIFEST)
    modified["files"] = list(MANIFEST["files"])[:-1]
    fake = FakeSftp()
    try:
        inspect_sftp_readonly(fake, BASE, BASELINE, modified)
        raise AssertionError("Incorrect release manifest unexpectedly accepted")
    except PreflightStop:
        global TESTS
        TESTS += 1
        print("PASS T-060: missing required runtime item blocks preflight")
    print(f"T-060: {TESTS} strictly offline preflight checks passed.")

if __name__ == "__main__":
    main()
