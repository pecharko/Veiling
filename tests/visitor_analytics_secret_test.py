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

if __name__=="__main__":
    unittest.main()
