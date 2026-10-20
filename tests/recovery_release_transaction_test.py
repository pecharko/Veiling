#!/usr/bin/env python3
"""Isolated fault-injection tests; no real SFTP connections."""
import io
import stat
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "scripts"))
from plan_catalog_recovery_release import FILES
from recovery_release_transaction import simulate_transaction


class FakeHandle:
    def __init__(self, store, path, mode, fail=False):
        self.store, self.path, self.mode, self.fail = store, path, mode, fail
        self.buffer = io.BytesIO(store.get(path, b"") if mode == "rb" else b"")

    def __enter__(self):
        return self

    def __exit__(self, *args):
        if self.mode == "wb":
            self.store[self.path] = self.buffer.getvalue()

    def read(self, size):
        return self.buffer.read(size)

    def write(self, value):
        if self.fail:
            self.buffer.write(value[:2])
            raise OSError("injected partial write")
        self.buffer.write(value)

    def flush(self):
        pass


class FakeSftp:
    def __init__(self, files, failure=None):
        self.files = dict(files)
        self.failure = failure
        self.failed_once = False
        self.writes = []
        self.removes = []

    def lstat(self, path):
        if path not in self.files:
            raise FileNotFoundError(path)
        class Info:
            st_mode = stat.S_IFREG | 0o600
            st_size = 0
        info = Info()
        info.st_size = len(self.files[path])
        return info

    def open(self, path, mode):
        if mode == "wb":
            self.writes.append(path)
        inject = mode == "wb" and path == self.failure and not self.failed_once
        if inject:
            self.failed_once = True
        return FakeHandle(self.files, path, mode, fail=inject)

    def remove(self, path):
        self.removes.append(path)
        self.files.pop(path)


ROOT = "/example"
OLD = {name: (None if i < 2 else ("original" + str(i)).encode())
       for i, name in enumerate(FILES)}
NEW = {name: ("replacement" + str(i)).encode()
       for i, name in enumerate(FILES)}
FILES_ON_SERVER = {ROOT + "/" + name: old for name, old in OLD.items()
                   if old is not None}


class TransactionTests(unittest.TestCase):
    def test_success_order(self):
        remote = FakeSftp(FILES_ON_SERVER)
        touched = simulate_transaction(remote, ROOT, OLD, NEW)
        self.assertEqual(touched, list(FILES))
        self.assertEqual(remote.writes, [ROOT + "/" + n for n in FILES])
        self.assertEqual(remote.files[ROOT + "/" + FILES[-1]], NEW[FILES[-1]])

    def test_partial_write_rolls_back_all_touched(self):
        remote = FakeSftp(FILES_ON_SERVER, ROOT + "/" + FILES[3])
        with self.assertRaisesRegex(RuntimeError, "offline rollback verified"):
            simulate_transaction(remote, ROOT, OLD, NEW)
        self.assertEqual(remote.files, FILES_ON_SERVER)
        self.assertEqual(remote.removes, [ROOT + "/" + FILES[1], ROOT + "/" + FILES[0]])

    def test_drift_stops_without_write(self):
        remote = FakeSftp(FILES_ON_SERVER)
        remote.files[ROOT + "/" + FILES[2]] = b"changed"
        with self.assertRaisesRegex(RuntimeError, "Production drift"):
            simulate_transaction(remote, ROOT, OLD, NEW)
        self.assertEqual(remote.writes, [])

    def test_missing_helper_required(self):
        remote = FakeSftp(FILES_ON_SERVER)
        remote.files[ROOT + "/" + FILES[0]] = b"surprise"
        with self.assertRaisesRegex(RuntimeError, "Production drift"):
            simulate_transaction(remote, ROOT, OLD, NEW)
        self.assertEqual(remote.writes, [])

    def test_rollback_failure_is_reported_not_hidden(self):
        class RollbackFailureSftp(FakeSftp):
            def open(self, path, mode):
                if (mode == "wb" and self.failed_once
                        and path == ROOT + "/" + FILES[2]):
                    raise OSError("injected failure restoring original")
                return super().open(path, mode)

        remote = RollbackFailureSftp(FILES_ON_SERVER, ROOT + "/" + FILES[3])
        with self.assertRaisesRegex(RuntimeError, "ROLLBACK INCOMPLETE"):
            simulate_transaction(remote, ROOT, OLD, NEW)
        self.assertNotEqual(remote.files, FILES_ON_SERVER)

    def test_wrong_root_is_rejected_without_writes(self):
        remote = FakeSftp(FILES_ON_SERVER)
        with self.assertRaises(ValueError):
            simulate_transaction(remote, "../untrusted", OLD, NEW)
        self.assertEqual(remote.writes, [])

    def test_exact_allowlist_required(self):
        remote = FakeSftp(FILES_ON_SERVER)
        changed = dict(NEW)
        changed["private/config.php"] = b"unsafe"
        with self.assertRaisesRegex(ValueError, "allowlist"):
            simulate_transaction(remote, ROOT, OLD, changed)
        self.assertEqual(remote.writes, [])


if __name__ == "__main__":
    unittest.main()
