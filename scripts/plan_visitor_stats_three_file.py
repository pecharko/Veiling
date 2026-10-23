#!/usr/bin/env python3
"""Offline-only visitor tracking three-file candidate verification; no network or upload."""
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FILES = ("private/visitor_stats.php", "catalogus-public/index.php", "catalogus-public/kavel.php")
KNOWN_REMOTE = {
    "private/visitor_stats.php": None,
    "catalogus-public/index.php": "d6f5bf173c3fcb419727c33caeaab0bc6e7b54d19e6f78d9b6f716794c78ae27",
    "catalogus-public/kavel.php": "9c375dbcc23b26ac2fa00338f369d085faff3a91f7de1f283808db5197761668",
}
def plan():
    result = []
    for name in FILES:
        path = ROOT / name
        if not path.is_file() or path.is_symlink():
            raise RuntimeError("Invalid source " + name)
        data = path.read_bytes()
        if not data or len(data) > 1024 * 1024:
            raise RuntimeError("Invalid source size " + name)
        if name == FILES[0]:
            required = (b"function track_catalog_view(", b"INSERT INTO visitor_stats_daily", b"HTTP_DNT")
        elif name == FILES[1]:
            required = (b"private/visitor_stats.php", b"track_catalog_view();")
        else:
            required = (b"private/visitor_stats.php", b"track_catalog_view((int)$lot['id']);")
        if not all(x in data for x in required):
            raise RuntimeError("Tracking integration missing in " + name)
        result.append({"path": name, "source_sha256": hashlib.sha256(data).hexdigest(),
                       "observed_remote_sha256": KNOWN_REMOTE[name],
                       "observed_remote_absent": KNOWN_REMOTE[name] is None,
                       "diff_bytes_obtained": False})
    for admin in (ROOT / "catalogus-public/admin").glob("*.php"):
        if b"track_catalog_view(" in admin.read_bytes():
            raise RuntimeError("Admin page tracking violates requirement: " + admin.name)
    return {"mode": "offline_only", "sources": result,
            "production_schema_verified": False, "production_diff_reviewed": False,
            "upload_allowed": False, "database_writes_allowed": False}

if __name__ == "__main__":
    print(json.dumps(plan(), indent=2, sort_keys=True))
