"""Synthetic-only verification: exact .htaccess baseline read; never write OVH."""
import io
import stat
import unittest
from types import SimpleNamespace
from scripts import readonly_htaccess_baseline as module


class FakeSftp:
    def __init__(self, contents=b"RewriteEngine On\n", bad_root=False, absent=False, symlink=False):
        self.files = {"/fixture/catalogus-public/admin/index.php":
                      b"wrong-root" if bad_root else b"dashboard-table"}
        self.path = "/fixture/" + module.TARGET
        if not absent:
            self.files[self.path] = contents
        self.symlink = symlink
        self.reads = []

    def __enter__(self):
        return self

    def __exit__(self, *_):
        return False

    def lstat(self, path):
        if path not in self.files:
            raise FileNotFoundError(2, "not present")
        mode = stat.S_IFLNK if self.symlink and path == self.path else stat.S_IFREG
        return SimpleNamespace(st_size=len(self.files[path]), st_mode=mode | 0o600)

    def open(self, path, mode):
        if mode != "rb":
            raise AssertionError("Write forbidden")
        self.reads.append(path)
        return io.BytesIO(self.files[path])

    def remove(self, *_):
        raise AssertionError("Write forbidden")


class BaselineTests(unittest.TestCase):
    def collect(self, **kwargs):
        sftp = FakeSftp(**kwargs)
        client = SimpleNamespace(open_sftp=lambda: sftp, close=lambda: None)
        result = module.observe(lambda: (client, "/fixture"))
        self.assertEqual(sftp.reads, [
            "/fixture/catalogus-public/admin/index.php", "/fixture/catalogus-public/.htaccess"
        ] if "absent" not in kwargs or not kwargs["absent"] else
            ["/fixture/catalogus-public/admin/index.php"])
        return result

    def test_present_sha(self):
        row = self.collect()
        self.assertEqual(row["path"], module.TARGET)
        self.assertEqual(row["state"], "PRESENT")
        self.assertEqual(row["remote_sha256"], module.engine.digest(b"RewriteEngine On\n"))

    def test_absent(self):
        self.assertEqual(self.collect(absent=True)["remote_sha256"], "ABSENT")

    def test_empty_is_not_accepted_as_baseline(self):
        with self.assertRaisesRegex(RuntimeError, "empty"):
            self.collect(contents=b"")

    def test_symlink_is_not_accepted(self):
        with self.assertRaisesRegex(RuntimeError, "unsafe"):
            self.collect(symlink=True)

    def test_wrong_root_is_rejected(self):
        with self.assertRaisesRegex(RuntimeError, "Incorrect OVH root"):
            self.collect(bad_root=True)


if __name__ == "__main__":
    unittest.main()
