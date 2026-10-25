#!/usr/bin/env python3
"""Emit strictly reviewed release profiles for the generic GitHub Actions matrix."""
import json
from pathlib import Path

REGISTRY = Path(__file__).with_name("auto_readonly_release_registry.json")
ALLOWED = {
    "kavelnummer-thumbnails": ("kavelnummer-thumbnails-release.yml", "scripts/deploy_lot_number_thumbnail.py", "tests/admin_lot_cover_contract_test.php", "preflight-sha256.json", "kavel-preflight-sha256"),
    "veiling-organisatie": ("auction-organisation-release.yml", "scripts/deploy_auction_organisation.py", "tests/auction_organisation_test.php", "auction-organisation-preflight.json", "auction-organisation-preflight"),
}

def profiles():
    document = json.loads(REGISTRY.read_text())
    if set(document) != {"schema", "profiles"} or document["schema"] != 1:
        raise ValueError("Bad read-only release registry")
    result = document["profiles"]
    if not isinstance(result, list) or not result:
        raise ValueError("No registered profiles")
    seen = set()
    for profile in result:
        if set(profile) != {"id", "workflow", "script", "test", "manifest", "artifact_prefix"}:
            raise ValueError("Unexpected fields in release registry")
        name = profile["id"]
        if name not in ALLOWED or name in seen:
            raise ValueError("Unknown/duplicate release profile")
        seen.add(name)
        if tuple(profile[k] for k in ("workflow", "script", "test", "manifest", "artifact_prefix")) != ALLOWED[name]:
            raise ValueError("Unreviewed read-only command or artifact")
    return result

if __name__ == "__main__":
    print(json.dumps({"include": profiles()}, separators=(",", ":")))
