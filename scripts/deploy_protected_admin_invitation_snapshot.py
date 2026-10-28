#!/usr/bin/env python3
"""Protected snapshot only; reuse shared release engine without weakening its generic denylist."""
import hashlib
import os
from pathlib import Path
import re
import sys
from scripts import shared_ovh_release as engine

TARGET = "private/snapshots.php"
RELEASE_ID = "protected-admin-invitations-354-20261028"
SOURCE_SHA = "85ced2e256374f830e5fd20d4b32c569f2547fad2f1feb2665a02aceca0413de"
REMOTE_SHA = "eba4d6f3e1ec958acc12e91397635589d09077d475ab192f4538ff413c0c22d8"

def guarded_load(release_id, *, source_root=None):
    if release_id != RELEASE_ID:
        raise ValueError("Invalid protected release ID")
    root = engine.ROOT if source_root is None else source_root
    file = root / TARGET
    if file.is_symlink() or not file.is_file() or not (0 < file.stat().st_size <= engine.MAX_FILE):
        raise ValueError("Missing or unsafe protected source")
    raw = file.read_bytes()
    if hashlib.sha256(raw).hexdigest() != SOURCE_SHA:
        raise ValueError("Protected source changed; review and reauthorize")
    return {"schema": 1, "files": [{"path": TARGET, "source_sha256": SOURCE_SHA, "remote_sha256": REMOTE_SHA}]}, {TARGET: raw}, {TARGET: REMOTE_SHA}

def main():
    if len(sys.argv) != 2 or sys.argv[1] not in ("plan", "deploy"):
        raise ValueError("Only plan or deploy")
    mode = sys.argv[1]
    if mode == "deploy":
        main_sha = os.environ.get("GITHUB_SHA", "")
        confirm = os.environ.get("DEPLOY_CONFIRMATION", "")
        if not re.fullmatch("[a-f0-9]{40}", main_sha) or confirm != "PROTECTED-SNAPSHOT-DEPLOY-" + RELEASE_ID + "-" + main_sha:
            raise ValueError("Explicit protected snapshot authorization required")
        # Shared engine independently checks its DEPLOY confirmation before any write.
        os.environ["DEPLOY_CONFIRMATION"] = "DEPLOY-" + RELEASE_ID + "-" + main_sha
    # The shared engine expects its adjacent evidence contract as a top-level import.\n    scripts_dir = str(engine.ROOT / "scripts")\n    if scripts_dir not in sys.path:\n        sys.path.insert(0, scripts_dir)\n    engine.load_manifest = guarded_load\n    sys.argv = ["shared_ovh_release.py", mode, RELEASE_ID]
    engine.run()

if __name__ == "__main__":
    main()
