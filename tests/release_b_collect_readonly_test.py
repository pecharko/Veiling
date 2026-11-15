"""Offline SFTP configuration security gates; no network involved."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from release_b_collect_readonly import config

ENV = {
    "OVH_READONLY_SFTP_HOST": "sftp.example.test",
    "OVH_READONLY_SFTP_USER": "limited",
    "OVH_READONLY_SFTP_PASSWORD": "fixture-only",
    "OVH_READONLY_SFTP_KNOWN_HOSTS": "sftp.example.test ssh-ed25519 AAAAfixtureOnly",
    "OVH_READONLY_SFTP_BASE": "/www/site",
}

class ConfigTest(unittest.TestCase):
    def test_requires_separate_scoped_credentials(self):
        host, user, password, known, root = config(ENV)
        self.assertEqual(host, "sftp.example.test")
        self.assertEqual(root, "/www/site")
        self.assertEqual(user, "limited")

    def test_never_falls_back_to_uploader_credentials(self):
        with self.assertRaises(ValueError):
            config({"OVH_SFTP_HOST": "sftp.example.test",
                    "OVH_SFTP_USER": "uploader", "OVH_SFTP_PASSWORD": "test"})

    def test_reject_missing_pin_and_invalid_root(self):
        for key, value in (("OVH_READONLY_SFTP_KNOWN_HOSTS", ""),
                           ("OVH_READONLY_SFTP_KNOWN_HOSTS", "other.test ssh-ed25519 AAAA"),
                           ("OVH_READONLY_SFTP_BASE", "/"),
                           ("OVH_READONLY_SFTP_BASE", "/www/../etc"),
                           ("OVH_READONLY_SFTP_HOST", "-invalid")):
            with self.subTest(key=key), self.assertRaises(ValueError):
                config({**ENV, key: value})

if __name__ == "__main__":
    unittest.main()
