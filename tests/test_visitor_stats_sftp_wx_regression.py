"""Regression: Paramiko exclusive create MUST also be writable (PR #66)."""
import importlib.util
from pathlib import Path
import sys
import types
import unittest
from unittest.mock import patch

SCRIPTS = Path(__file__).resolve().parents[1] / "scripts"
sys.path.insert(0, str(SCRIPTS))
import deploy_visitor_stats_two_files as release


class FakeSFTP:
    def __init__(self):
        self.modes = []
        self.written = {}

    def open(self, path, mode):
        self.modes.append(mode)
        if mode != "wx":
            raise OSError("File not open for writing")
        class Writable:
            def __enter__(inner):
                return inner
            def __exit__(inner, *_):
                return False
            def write(inner, value):
                self.written[path] = value
            def flush(inner):
                pass
        return Writable()

class FakeClient:
    def __init__(self, sftp):
        self.sftp = sftp
    def open_sftp(self):
        class Context:
            def __enter__(inner):
                return self.sftp
            def __exit__(inner, *_):
                return False
        return Context()
    def close(self):
        pass

class ExclusiveWritableTests(unittest.TestCase):
    def test_two_file_create_requires_both_x_and_w(self):
        """Fail deterministically for 'x', without touching OVH."""
        sftp = FakeSFTP()
        source = {name: (b"demo " + name.encode()) for name in release.FILES}
        with patch.object(release, "sources", return_value=source), \
             patch.object(release, "connect", return_value=FakeClient(sftp)), \
             patch.object(release, "remote_root", return_value="/fake"), \
             patch.object(release, "check_remote"), \
             patch.dict("os.environ", {"VISITOR_RELEASE_CONFIRMATION": "DEPLOY VISITOR STATS TWO NEW FILES"}):
            # Only exercise the exclusive-create write stage, with fake reads/stats.
            import stat
            original_open = sftp.open
            def fake_open(path, mode):
                if mode == "rb":
                    class Readable:
                        def __enter__(inner): return inner
                        def __exit__(inner, *_): return False
                        def read(inner, *_): return sftp.written[path]
                    return Readable()
                return original_open(path, mode)
            sftp.open = fake_open
            sftp.lstat = lambda path: types.SimpleNamespace(st_mode=stat.S_IFREG | 0o644, st_size=len(sftp.written[path]))
            release.main("deploy")
        self.assertEqual(sftp.modes, ["wx", "wx"])
        self.assertEqual(len(sftp.written), 2)

if __name__ == "__main__":
    unittest.main()
