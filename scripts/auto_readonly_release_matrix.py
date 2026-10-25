#!/usr/bin/env python3
"""Emit strictly reviewed release profiles for the generic GitHub Actions matrix."""
import json
from pathlib import Path

REGISTRY = Path(__file__).with_name("auto_readonly_release_registry.json")
ALLOWED = {
    "kavelnummer-thumbnails": ("kavelnummer-thumbnails-release.yml", "scripts/deploy_lot_number_thumbnail.py", "tests/admin_lot_cover_contract_test.php", "preflight-sha256.json", "kavel-preflight-sha256"),
    "veiling-organisatie": ("auction-organisation-release.yml", "scripts/deploy_auction_organisation.py", "tests/auction_organisation_test.php", "auction-organisation-preflight.json", "auction-organisation-preflight"),
    "session-roles": ("session-roles-release.yml", "scripts/deploy_session_roles.py", "tests/admin_session_role_contract_test.php", "session-roles-preflight.json", "session-roles-preflight"),
    "role-menu": ("role-menu-release.yml", "scripts/deploy_role_menu.py", "tests/admin_role_menu_contract_test.php", "role-menu-preflight.json", "role-menu-preflight"),
    "site-online-sixfile": ("site-online-sixfile-release.yml", "scripts/deploy_site_online_sixfile.py", "tests/site_online_switch_contract_test.php", "site-online-preflight.json", "site-online-preflight"),
    "lot-visibility-twofile": ("lot-visibility-twofile-release.yml", "scripts/deploy_lot_visibility_twofile.py", "tests/public_lot_visibility_contract_test.php", "lot-visibility-preflight.json", "lot-visibility-preflight"),
    "roles-page-onefile": ("roles-page-onefile-release.yml", "scripts/deploy_roles_page_onefile.py", "tests/admin_role_menu_contract_test.php", "roles-page-preflight.json", "roles-page-preflight"),
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
