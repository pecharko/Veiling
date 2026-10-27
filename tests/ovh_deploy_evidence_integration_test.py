"""Offline deploy-evidence integration tests: no SFTP network or production writes."""
import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))
spec = importlib.util.spec_from_file_location("release_under_test", ROOT / "scripts/shared_ovh_release.py")
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

PATH = "catalogus-public/admin/admin.css"
DATA = b"body { color: green; }"
SENTINEL = b"dashboard-table"

class Remote:
    def __init__(self, upload_fails=False):
        self.files = {"/site/catalogus-public/admin/index.php": SENTINEL}
        self.upload_fails = upload_fails

    def __enter__(self): return self
    def __exit__(self, *args): return False
    def remove(self, name): self.files.pop(name, None)

class Client:
    def __init__(self, remote): self.remote = remote
    def open_sftp(self): return self.remote
    def close(self): pass

class FakeWriter:
    def __init__(self, remote, name): self.remote, self.name = remote, name
    def __enter__(self): return self
    def __exit__(self, *args): return False
    def write(self, data):
        if self.remote.upload_fails:
            raise OSError("simulated upload failure")
        self.remote.files[self.name] = data
    def flush(self): pass

def read_remote(remote, name):
    return remote.files.get(name)

class EvidenceIntegration(unittest.TestCase):
    def execute(self, upload_fails=False, drift=False, connect_fails=False):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            backup = root / "deployment-backups"
            remote = Remote(upload_fails=upload_fails)
            if drift:
                remote.files["/site/" + PATH] = b"unexpected remote content"
            remote.open = lambda name, mode: FakeWriter(remote, name)
            manifest = ({"schema": 1, "files": []}, {PATH: DATA}, {PATH: "ABSENT"})
            def connector():
                if connect_fails: raise OSError("simulated connect failure")
                return Client(remote), "/site"
            with (patch.object(mod, "ROOT", root),
                  patch.object(mod, "BACKUP", backup),
                  patch.object(mod, "load_manifest", return_value=manifest),
                  patch.object(mod, "connect", side_effect=connector),
                  patch.object(mod, "read_remote", side_effect=read_remote),
                  patch.object(sys, "argv", ["shared_ovh_release.py", "deploy", "abc"]),
                  patch.dict("os.environ", {
                      "GITHUB_RUN_ID": "123", "GITHUB_SHA": "b" * 40,
                      "DEPLOY_CONFIRMATION": "DEPLOY-abc-" + "b" * 40
                  })):
                if upload_fails or drift or connect_fails:
                    with self.assertRaises((OSError, RuntimeError)):
                        mod.run()
                else:
                    mod.run()
            evidence = json.loads((root / "deployment-evidence.json").read_text())
            return evidence, remote.files

    def test_verified_after_real_mocked_readback(self):
        evidence, files = self.execute()
        self.assertEqual(evidence["status"], "VERIFIED")
        self.assertEqual(evidence["files"][0]["readback_sha256"], mod.digest(DATA))
        self.assertEqual(files["/site/" + PATH], DATA)

    def test_remote_drift_fails_closed(self):
        evidence, files = self.execute(drift=True)
        self.assertEqual(evidence["status"], "FAILED")
        self.assertIsNone(evidence["files"][0]["readback_sha256"])
        self.assertEqual(files["/site/" + PATH], b"unexpected remote content")

    def test_upload_error_with_rollback(self):
        evidence, _ = self.execute(upload_fails=True)
        # In this scenario rollback removes the previously absent target.
        self.assertEqual(evidence["status"], "ROLLED_BACK")

    def test_connect_failure_still_publishes_failure_evidence(self):
        evidence, _ = self.execute(connect_fails=True)
        self.assertEqual(evidence["status"], "FAILED")

if __name__ == "__main__":
    unittest.main()
