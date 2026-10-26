#!/usr/bin/env python3
"""Offline-only, registered release checks. Never connects to production."""
import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REGISTRY = ROOT / "scripts" / "generic_offline_release_registry.json"

def safe_file(value, suffix):
    if not isinstance(value, str) or not re.fullmatch(r"[a-zA-Z0-9_./-]+", value):
        raise ValueError("Invalid registered path")
    if ".." in value.split("/") or not value.endswith(suffix):
        raise ValueError("Unsafe registered path")
    p = ROOT / value
    if not p.is_file() or p.is_symlink() or not p.resolve().is_relative_to(ROOT):
        raise ValueError("Missing/unsafe registered file: " + value)
    return p

def run():
    document = json.loads(REGISTRY.read_text(encoding="utf-8"))
    if set(document) != {"schema", "releases"} or document["schema"] != 1:
        raise ValueError("Invalid offline release registry")
    releases = document["releases"]
    if not isinstance(releases, dict) or not releases:
        raise ValueError("No registered offline releases")
    report = {}
    for release, profile in releases.items():
        if not re.fullmatch(r"[a-z0-9-]{2,60}", release):
            raise ValueError("Invalid release ID")
        if set(profile) != {"php_files", "migration", "required_columns"}:
            raise ValueError("Unreviewed offline profile fields")
        files = profile["php_files"]
        columns = profile["required_columns"]
        if not isinstance(files, list) or not files or len(files) != len(set(files)) or len(files) > 40:
            raise ValueError("Invalid PHP allowlist")
        if not isinstance(columns, list) or len(columns) != len(set(columns)) or len(columns) > 30:
            raise ValueError("Invalid SQL column list")
        hashes = {}
        for item in files:
            if not item.startswith(("catalogus-public/", "private/")):
                raise ValueError("Unapproved PHP root")
            p = safe_file(item, ".php")
            hashes[item] = hashlib.sha256(p.read_bytes()).hexdigest()
        migration = profile["migration"]
        if migration is not None:
            if not migration.startswith("database/"):
                raise ValueError("Invalid migration root")
            sql = safe_file(migration, ".sql").read_text(encoding="utf-8")
            if re.search(r"\bDROP\s+(?:TABLE|COLUMN|DATABASE)\b", sql, re.IGNORECASE):
                raise ValueError("Destructive migration requires separate review")
            for column in columns:
                if not re.fullmatch(r"[a-z][a-z0-9_]{1,60}", column):
                    raise ValueError("Invalid SQL column identifier")
                if len(re.findall(r"\bADD\s+COLUMN\s+" + re.escape(column) + r"\b", sql, re.IGNORECASE)) != 1:
                    raise ValueError("Missing/duplicate migration column: " + column)
            hashes[migration] = hashlib.sha256(sql.encode("utf-8")).hexdigest()
        elif columns:
            raise ValueError("Columns need a migration")
        report[release] = hashes
    output = ROOT / "generic-offline-release-evidence.json"
    output.write_text(json.dumps({"schema": 1, "releases": report}, sort_keys=True, indent=2) + "\n")
    print("Offline evidence written for", len(report), "registered releases")

if __name__ == "__main__":
    run()
