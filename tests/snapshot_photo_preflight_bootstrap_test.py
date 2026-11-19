"""Offline regression for snapshot-photo SFTP root identity gate; no network."""
import unittest
from pathlib import Path
from unittest.mock import patch

from scripts import deploy_snapshot_photos_onefile as release


class BootstrapIdentityTest(unittest.TestCase):
    def test_current_repository_bootstrap_is_accepted(self):
        current = (Path(__file__).resolve().parents[1] / "private/bootstrap.php").read_bytes()
        self.assertTrue(release.has_expected_auth_guard(current))

    def test_supported_auth_definitions(self):
        for source in (
            b"<?php\nfunction require_auth() {\n}\n",
            b"<?php\nfunction require_auth(bool $touchActivity = true): void {\n}\n",
        ):
            with self.subTest(source=source):
                self.assertTrue(release.has_expected_auth_guard(source))

    def test_wrong_or_forged_bootstrap_rejected(self):
        for source in (
            b"<?php\n// function require_auth() {\n",
            b"<?php\n$example = 'function require_auth() {';\n",
            b"<?php\nrequire_auth();\n",
            b"<?php\nfunction require_authenticated() {}\n",
            b"<?php\nfunction require_auth(bool $touchActivity = false): void {}\n",
            b"<?php\nfunction require_auth(bool $other = true): void {}\n",
            b"<?php\nfunction require_auth() ;\n",
        ):
            with self.subTest(source=source):
                self.assertFalse(release.has_expected_auth_guard(source))

    def test_inspect_requires_both_admin_sentinel_and_auth_guard(self):
        base = "/home/example"
        admin = base + "/catalogus-public/admin/index.php"
        snapshot = base + "/private/snapshots.php"
        bootstrap = base + "/private/bootstrap.php"
        valid = {
            admin: b"dashboard-table",
            snapshot: b"<?php // disposable snapshot fixture",
            bootstrap: b"<?php\nfunction require_auth(bool $touchActivity = true): void {}",
        }
        def read(_sftp, path, allow_absent=False):
            return valid[path]
        with patch.object(release, "remote_read", side_effect=read):
            self.assertEqual(release.inspect(object(), base), {"private/snapshots.php": valid[snapshot]})
        for broken in (
            {**valid, admin: b"not-this-application"},
            {**valid, bootstrap: b"<?php\n// function require_auth() {"},
        ):
            with self.subTest(broken=broken):
                with patch.object(release, "remote_read", side_effect=lambda _sftp, path, allow_absent=False: broken[path]):
                    with self.assertRaisesRegex(RuntimeError, "Wrong remote"):
                        release.inspect(object(), base)


if __name__ == "__main__":
    unittest.main()
