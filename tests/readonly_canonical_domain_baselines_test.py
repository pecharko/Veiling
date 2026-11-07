"""Offline safety contract for the canonical-domain read-only baseline collector."""
import io
import stat
import unittest
from types import SimpleNamespace

from scripts import readonly_canonical_domain_baselines as scan


class FakeSftp:
    def __init__(self, missing=None, bad_sentinel=False):
        self.missing = missing
        self.bad_sentinel = bad_sentinel
        self.files = {
            "/fixture/catalogus-public/admin/index.php": (
                b"wrong-root" if bad_sentinel else b"dashboard-table"
            ),
        }
        for path in scan.TARGETS:
            if path != missing:
                self.files["/fixture/" + path] = ("fixture " + path).encode()

    def __enter__(self):
        return self

    def __exit__(self, *_):
        return None

    def lstat(self, path):
        if path not in self.files:
            raise FileNotFoundError(2, "missing")
        return SimpleNamespace(st_mode=stat.S_IFREG | 0o600, st_size=len(self.files[path]))

    def open(self, path, mode):
        if mode != "rb":
            raise AssertionError("Write forbidden")
        return io.BytesIO(self.files[path])

    def remove(self, *_):
        raise AssertionError("Write forbidden")


class CanonicalReadonlyTests(unittest.TestCase):
    def fake(self, **kwargs):
        sftp = FakeSftp(**kwargs)
        client = SimpleNamespace(open_sftp=lambda: sftp, close=lambda: None)
        return lambda: (client, "/fixture")

    def test_exact_allowlist(self):
        self.assertEqual(scan.TARGETS, (
            "catalogus-public/.htaccess",
            "catalogus-public/index.php",
            "catalogus-public/kavel.php",
            "catalogus-public/pecharko.php",
            "catalogus-public/vorige-editie.php",
        ))

    def test_hashes_only_without_remote_writes(self):
        hashes = scan.collect(self.fake())
        self.assertEqual(set(hashes), set(scan.TARGETS))
        self.assertTrue(all(len(h) == 64 for h in hashes.values()))

    def test_missing_is_absent_not_guessed(self):
        missing = scan.TARGETS[0]
        self.assertEqual(scan.collect(self.fake(missing=missing))[missing], "ABSENT")

    def test_wrong_root_rejected(self):
        with self.assertRaisesRegex(RuntimeError, "remote root"):
            scan.collect(self.fake(bad_sentinel=True))


if __name__ == "__main__":
    unittest.main()
