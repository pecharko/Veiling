"""Offline checks for bounded read-only OVH baseline collection."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from read_only_ovh_baselines import validate_paths, observe


class BaselinesTest(unittest.TestCase):
    def test_accepts_two_public_files(self):
        self.assertEqual(validate_paths("catalogus-public/vorige-editie.php\ncatalogus-public/assets/vorige-editie.css"), [
            "catalogus-public/vorige-editie.php", "catalogus-public/assets/vorige-editie.css"])

    def test_accepts_space_separated_paths(self):
        raw = "catalogus-public/vorige-editie.php catalogus-public/assets/vorige-editie.css"
        self.assertEqual(validate_paths(raw), ["catalogus-public/vorige-editie.php", "catalogus-public/assets/vorige-editie.css"])

    def test_rejects_unsafe_inputs(self):
        for text in ("", "catalogus-public/../private/.env", "private/foo.php",
                     "catalogus-public/a.php\ncatalogus-public/a.php", "catalogus-public/a.txt",
                     "catalogus-public/admin/.env.php", "catalogus-public//x.php"):
            with self.subTest(text=text), self.assertRaises(ValueError):
                validate_paths(text)

    def test_hashes_or_absent(self):
        from hashlib import sha256
        paths = validate_paths("catalogus-public/a.php\ncatalogus-public/b.php")
        rows = observe(paths, lambda name: b"hello" if name.endswith("a.php") else None)
        self.assertEqual(rows[0]["remote_sha256"], sha256(b"hello").hexdigest())
        self.assertEqual(rows[1]["remote_sha256"], "ABSENT")


if __name__ == "__main__":
    unittest.main()
