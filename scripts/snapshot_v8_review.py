"""Offline, fail-closed review of the v8 catalog-only snapshot contract.

PR validation ONLY. This module does not provide a deployment controller,
authorization, release manifest, GitHub API client, or OVH/SFTP connection.
Historical protected release adapters must never reuse this check for deploy.
"""
from pathlib import Path
import re
import sys

TARGET = "private/snapshots.php"
FORMAT = "41lokeren-v8-catalog-only"
TABLES = ("settings", "lot_categories", "lot_sellers", "lots", "lot_images", "seller_images")
FORBIDDEN = ("admins", "login_attempts", "admin_invitations", "admin_password_resets")


def review_source(source: str) -> None:
    if not source or len(source) > 500_000:
        raise ValueError("Missing or oversized v8 snapshot source")
    expected = "const SNAP_TABLES = [" + ",".join(repr(table) for table in TABLES) + "];"
    if source.count(expected) != 1:
        raise ValueError("Unexpected v8 snapshot table scope")
    if source.count("'" + FORMAT + "'") != 3:
        raise ValueError("Unexpected v8 format declaration or verification")
    if re.search(r"41lokeren-v[0-79]-", source):
        raise ValueError("Legacy format may not be accepted in v8 restore")
    for table in TABLES:
        if not re.search(r"(?m)^\s*'" + re.escape(table) + r"'\s*=>\s*\[", source):
            raise ValueError("Missing v8 insert column contract: " + table)
    for table in FORBIDDEN:
        if re.search(r"(?m)^\s*'" + re.escape(table) + r"'\s*=>\s*\[", source):
            raise ValueError("Authentication table included in catalog snapshot: " + table)
        if re.search(r"(?i)DELETE\s+FROM\s+`?" + re.escape(table) + r"`?", source):
            raise ValueError("Restore mutates live authentication table: " + table)
    for marker in (
        "foreach(['lot_images','seller_images','lots','lot_sellers','lot_categories','settings'] as $table)",
        "if(($database['format']??null)!=='" + FORMAT + "')",
        "snap_verified(",
        "snap_require_referenced_photos(",
        "restore_journal_",
    ):
        if marker not in source:
            raise ValueError("Missing v8 restore safety condition: " + marker)


def main() -> None:
    path = Path(TARGET)
    if not path.is_file() or path.is_symlink():
        raise SystemExit("Missing or unsafe protected v8 source")
    review_source(path.read_text(encoding="utf-8"))
    print("PASS: offline v8 snapshot contract; PR-only, no release authority")


if __name__ == "__main__":
    main()
