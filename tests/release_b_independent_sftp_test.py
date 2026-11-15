"""Offline unit tests for the independent read-only SFTP collector."""
import io
import stat
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from release_b_independent_sftp import collect, validate_root, read_one

UPLOAD = {
    "schema": 1, "release_id": "release-example", "release_sha": "a"*40,
    "run_id": 123, "status": "VERIFIED", "rollback_artifact": None,
    "browser_acceptance": "PENDING",
    "files": [{"path":"catalogus-public/asset.css", "source_sha256":"b"*64,
               "baseline_sha256":"c"*64, "readback_sha256":"b"*64}]
}

class Info:
    st_mode = stat.S_IFREG | 0o444
    st_size = 4
    st_mtime = 100

class FakeSFTP:
    def __init__(self, raw=b"test"):
        self.raw = raw
        self.calls = []
        self.mode = stat.S_IFREG | 0o444
    def lstat(self, path):
        self.calls.append(("lstat", path))
        info = Info()
        info.st_size = len(self.raw)
        info.st_mode = (stat.S_IFDIR | 0o555) if path in ("/site", "/site/www", "/site/www/catalogus-public") else self.mode
        return info
    def open(self, path, mode):
        self.calls.append(("open", path, mode))
        if mode != "rb":
            raise AssertionError("Unexpected remote write!")
        return io.BytesIO(self.raw)

class ReadbackTests(unittest.TestCase):
    def test_hashes_with_only_read_operations(self):
        sftp = FakeSFTP()
        r = collect(sftp, "/site/www", UPLOAD, "collection-20261115-01")
        self.assertEqual(r["files"], [{"path": "catalogus-public/asset.css",
                                         "remote_sha256": "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08"}])
        self.assertEqual([x[0] for x in sftp.calls], ["lstat"] * 3 + ["lstat", "open", "lstat"])
        self.assertEqual(r["origin"], "separate-read-only-sftp-collector")

    def test_reject_invalid_paths_before_network_use(self):
        for path in ("private/../secrets.php", "catalogus-public/.hidden",
                     "catalogus-public/asset.css/../../bad"):
            sftp = FakeSFTP()
            bad = {**UPLOAD, "files": [{**UPLOAD["files"][0], "path": path}]}
            with self.subTest(path=path), self.assertRaises(ValueError):
                collect(sftp, "/site/www", bad, "collection-20261115-01")
            self.assertEqual(sftp.calls, [])

    def test_reject_unsafe_roots(self):
        for root in ("/", "/site/../www", "/site//www", "site/www", "/site/.private"):
            with self.subTest(root=root), self.assertRaises(ValueError):
                validate_root(root)

    def test_reject_parent_symlink(self):
        class ParentSymlink(FakeSFTP):
            def lstat(self, path):
                info = super().lstat(path)
                if path == "/site/www":
                    info.st_mode = stat.S_IFLNK | 0o777
                return info
        sftp = ParentSymlink()
        with self.assertRaises(ValueError):
            collect(sftp, "/site/www", UPLOAD, "collection-20261115-01")
        self.assertFalse(any(call[0] == "open" for call in sftp.calls))

    def test_reject_symlinks(self):
        sftp = FakeSFTP()
        sftp.mode = stat.S_IFLNK | 0o777
        with self.assertRaises(ValueError):
            read_one(sftp, "/site/www/catalogus-public/asset.css")
        self.assertEqual(len(sftp.calls), 1)

    def test_reject_unverified_uploader(self):
        sftp = FakeSFTP()
        with self.assertRaises(ValueError):
            collect(sftp, "/site/www", {**UPLOAD, "status": "FAILED"},
                    "collection-20261115-01")
        self.assertEqual(sftp.calls, [])

    def test_reject_bad_session_id(self):
        sftp = FakeSFTP()
        with self.assertRaises(ValueError):
            collect(sftp, "/site/www", UPLOAD, "../unsafe")
        self.assertEqual(sftp.calls, [])

if __name__ == "__main__":
    unittest.main()
