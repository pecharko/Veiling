#!/usr/bin/env python3
"""Offline safety regressions for the hand-approved six-file release.

No network, SFTP credentials, databases or production state are used.
"""
from __future__ import annotations

import contextlib
import io
import json
import os
from pathlib import Path
import sys
import tempfile
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
import deploy_audit_release as d


def ensure(ok: bool, reason: str) -> None:
    if not ok:
        raise AssertionError(reason)
    print("PASS:", reason)


source = d.source_files()
ensure(len(source) == 6 and set(source) == set(d.FILES), "exact six-file runtime allowlist")
ensure(d.OPTIONAL_REMOTE == {"catalogus-public/assets/placeholder.svg"},
       "only the new placeholder may be absent on production")

with mock.patch.object(d, "connect", side_effect=AssertionError("SFTP used in plan")):
    with contextlib.redirect_stdout(io.StringIO()) as stream:
        d.plan()
    text = stream.getvalue()
    ensure(text.count("sha256=") == 6 and "no SFTP connection" in text,
           "offline plan prints six hashes and never connects")

with tempfile.TemporaryDirectory(prefix="safe-audit-release-test-") as temp:
    with mock.patch.object(d, "BACKUP_DIR", Path(temp) / "backups"):
        old = {name: (None if name in d.OPTIONAL_REMOTE else b"old-" + name.encode())
               for name in d.FILES}
        manifest = {
            "release": d.RELEASE, "base": "/safe-test-root",
            "previous_sha256": {n: d.digest(v) if v is not None else None
                                for n, v in old.items()},
            "new_sha256": {n: d.digest(v) for n, v in source.items()},
        }
        d.BACKUP_DIR.mkdir()
        for name, body in old.items():
            if body is None:
                continue
            target = d.BACKUP_DIR / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
        (d.BACKUP_DIR / "manifest.json").write_text(json.dumps(manifest), encoding="utf-8")
        ensure(d.load_backup("/safe-test-root", source) == old,
               "restorable manifest records an absent original SVG")
        wrong = {**source, d.FILES[0]: b"different SVG"}
        try:
            d.load_backup("/safe-test-root", wrong)
        except RuntimeError:
            ensure(True, "source hash drift is rejected")
        else:
            raise AssertionError("source drift was accepted")
        saved = d.BACKUP_DIR / d.FILES[1]
        saved.write_bytes(b"tampered")
        try:
            d.load_backup("/safe-test-root", source)
        except RuntimeError:
            ensure(True, "corrupt rollback artifact is rejected")
        else:
            raise AssertionError("tampered backup was accepted")

with tempfile.TemporaryDirectory(prefix="missing-audit-manifest-") as temp:
    with mock.patch.object(d, "BACKUP_DIR", Path(temp) / "missing"):
        with mock.patch.dict(os.environ, {"OVH_SFTP_BASE": "/safe-test-root"}):
            with mock.patch.object(d, "connect", side_effect=AssertionError("SFTP used without backup")):
                try:
                    d.upload()
                except RuntimeError:
                    ensure(True, "upload refuses to connect without a verified rollback manifest")
                else:
                    raise AssertionError("upload proceeded without manifest")

print("Audited release offline safety tests passed.")
