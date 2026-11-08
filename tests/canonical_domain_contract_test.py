#!/usr/bin/env python3
"""Offline regression checks for the public canonical-host contract."""
import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
PUBLIC = ROOT / "catalogus-public"

class CanonicalDomainContract(unittest.TestCase):
    def test_single_host_301_for_www_and_http(self):
        rules = (PUBLIC / ".htaccess").read_text(encoding="utf-8")
        self.assertIn(r"^www\.41lokeren\.be$", rules)
        self.assertIn("RewriteCond %{SERVER_PORT} ^80$", rules)
        self.assertIn("https://41lokeren.be%{REQUEST_URI}", rules)
        self.assertIn("[R=301,L]", rules)
        self.assertNotIn("https://%{HTTP_HOST}", rules)

    def test_unique_catalogue_canonicals(self):
        paths = {
            "index.php": 'href="https://41lokeren.be/',
            "kavel.php": 'href="https://41lokeren.be/kavel.php?nr=',
            "vorige-editie.php": 'href="https://41lokeren.be/vorige-editie.php"',
        }
        for name, fragment in paths.items():
            with self.subTest(name=name):
                php = (PUBLIC / name).read_text(encoding="utf-8")
                self.assertEqual(php.count('rel="canonical"'), 1)
                self.assertIn(fragment, php)
                self.assertNotIn('https://www.41lokeren.be', php)
                    self.assertIn("if (!admin_preview_active())", php)

    def test_nonindexable_privacy_stays_without_canonical(self):
        privacy = (PUBLIC / "privacy.php").read_text(encoding="utf-8")
        self.assertIn('name="robots" content="noindex,nofollow"', privacy)
        self.assertNotIn('rel="canonical"', privacy)

    def test_catalogue_pagination_and_detail_ids_stable(self):
        index = (PUBLIC / "index.php").read_text(encoding="utf-8")
        self.assertIn("$page > 1 ? '?pagina=' . $page : ''", index)
        kavel = (PUBLIC / "kavel.php").read_text(encoding="utf-8")
        self.assertIn('kavel.php?nr=<?=(int)$lotNum?>', kavel)

if __name__ == "__main__":
    unittest.main()
