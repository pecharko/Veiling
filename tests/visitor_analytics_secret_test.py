#!/usr/bin/env python3
"""Isolated source-level safety checks for manual analytics_secret configuration."""
import sys
import unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
import configure_visitor_analytics_secret as c

BASE = b"<?php\nreturn [\n 'base_path' => '',\n 'database' => ['host' => 'local'],\n];\n"
class Tests(unittest.TestCase):
    def test_patch_does_not_modify_existing_entries(self):
        key = "a" * 64
        result = c.patch_config(BASE, key)
        self.assertIn(b"'analytics_secret' => '" + key.encode() + b"'", result)
        self.assertIn(b"'database' => ['host' => 'local']", result)
        self.assertEqual(result.replace(b"\n  'analytics_secret' => '"+key.encode()+b"',",b""), BASE)

    def test_invalid_key_rejected(self):
        for key in ("", "a"*32, "not-random", "Z"*64):
            with self.assertRaises(c.PreflightStop):
                c.patch_config(BASE, key)

    def test_existing_key_rejected(self):
        with self.assertRaises(c.PreflightStop):
            c.patch_config(BASE.replace(b"'base_path'", b"'analytics_secret'"), "a"*64)

    def test_unknown_shape_rejected(self):
        with self.assertRaises(c.PreflightStop):
            c.patch_config(b"<?php return getenv('DATABASE');", "a"*64)

    def test_lint_original_and_modified(self):
        c.php_lint(BASE)
        c.php_lint(c.patch_config(BASE,"b"*64))

    def test_never_generates_key_in_inspect(self):
        class FakeSFTP:
            pass
        with patch.object(c, "verify_root"),patch.object(c,"read_private_config",return_value=(BASE,0o600)),patch.object(c,"php_lint"),patch.object(c,"secrets") as secret:
            c.inspect(FakeSFTP(),"/test")
            secret.token_hex.assert_not_called()

    def test_pending_temp_inventory_read_only(self):
        class Fake:
            def listdir(self, path):
                return [".config-analytics-39606484975.tmp", "config.php",
                        ".config-analytics-xyz.tmp", ".other"]
        self.assertEqual(c.pending_temp_count(Fake(), "/home/test"), 2)

    def test_diagnostics_do_not_disclose_sensitive_sftp_errors(self):
        from unittest.mock import MagicMock
        fake = MagicMock()
        secret_marker = "SECRET_VALUE_NEVER_LOG_THIS"
        fake.open.side_effect = RuntimeError(secret_marker)
        fake.listdir.return_value = []
        baseline = c.sha(BASE)
        with patch.object(c, "inspect", return_value=(baseline, 0o644)), \
             patch.object(c, "read_private_config", return_value=(BASE, 0o644)), \
             patch.object(c, "php_lint"), \
             patch.object(c, "restore_markers_clear"):
            with self.assertRaises(c.SafeStageFailure) as caught:
                c.install(fake, "/home/test", baseline, "12345")
        self.assertEqual(caught.exception.stage, "create-temp")
        self.assertNotIn(secret_marker, str(caught.exception))
        fake.posix_rename.assert_not_called()

    def test_temp_sftp_open_is_exclusive_and_writable(self):
        """Regression for production run 39609692869 (write-temp)."""
        from unittest.mock import MagicMock
        fake = MagicMock()
        baseline = c.sha(BASE)
        modes = []

        def check_open(path, mode):
            modes.append(mode)
            # Refuse a read-only exclusive create such as 'x'.
            self.assertIn("x", mode)
            self.assertIn("w", mode)
            raise OSError("simulated remote failure: should not print secrets")

        fake.open.side_effect = check_open
        with patch.object(c, "inspect", return_value=(baseline, 0o644)), \
             patch.object(c, "read_private_config", return_value=(BASE, 0o644)), \
             patch.object(c, "php_lint"), \
             patch.object(c, "restore_markers_clear"):
            with self.assertRaises(c.SafeStageFailure) as failure:
                c.install(fake, "/home/test", baseline, "12345")
        self.assertEqual(modes, ["wx"])
        self.assertEqual(failure.exception.stage, "create-temp")
        fake.posix_rename.assert_not_called()

    def test_install_stops_on_config_drift_before_sftp_writes(self):
        from unittest.mock import MagicMock
        fake = MagicMock()
        with patch.object(c, "inspect", return_value=("a"*64, 0o644)):
            with self.assertRaises(c.PreflightStop):
                c.install(fake, "/home/test", "b"*64, "12345")
        fake.open.assert_not_called()
        fake.posix_rename.assert_not_called()

if __name__=="__main__":
    unittest.main()
