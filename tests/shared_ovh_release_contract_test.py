"""Offline safety regressions for shared OVH writer. Never contacts OVH."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("shared_release", ROOT / "scripts/shared_ovh_release.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ManifestContract(unittest.TestCase):
    def test_reject_traversal_and_protected_targets(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            (root / "releases").mkdir()
            for path in ("private/../.env", "private/visitor_stats_daily_cron.php",
                         "catalogus-public/../../x.php", "scripts/a.py"):
                (root / "releases" / "abc.json").write_text(json.dumps({
                    "schema": 1, "files": [{"path": path,
                    "source_sha256": "0" * 64, "remote_sha256": "ABSENT"}]
                }))
                with patch.object(module, "ROOT", root):
                    with self.assertRaises(ValueError):
                        module.load_manifest("abc")

    def test_reject_missing_or_empty_manifest(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            (root / "releases").mkdir()
            (root / "releases" / "abc.json").write_text('{"schema":1,"files":[]}')
            with patch.object(module, "ROOT", root):
                with self.assertRaises(ValueError):
                    module.load_manifest("abc")
                with self.assertRaises(ValueError):
                    module.load_manifest("../abc")

    def test_valid_manifest_and_source_hashes(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            (root / "releases").mkdir()
            (root / "catalogus-public" / "admin").mkdir(parents=True)
            content = b"body{color:green}"
            (root / "catalogus-public" / "admin" / "admin.css").write_bytes(content)
            (root / "releases" / "abc.json").write_text(json.dumps({
                "schema": 1, "files": [{
                    "path": "catalogus-public/admin/admin.css",
                    "source_sha256": module.digest(content),
                    "remote_sha256": "a" * 64
                }]
            }))
            with patch.object(module, "ROOT", root):
                result, payload, baselines = module.load_manifest("abc")
            self.assertEqual(payload["catalogus-public/admin/admin.css"], content)
            self.assertEqual(baselines["catalogus-public/admin/admin.css"], "a" * 64)

    def test_no_unsafe_directories(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            (root / "releases").mkdir()
            for path in ("private/snapshots_backup.php", "private/config.php",
                         "private/.env.local", "catalogus-public/.hidden.php"):
                (root / "releases" / "abc.json").write_text(json.dumps({
                    "schema": 1, "files": [{"path": path,
                    "source_sha256": "0" * 64, "remote_sha256": "ABSENT"}]
                }))
                with patch.object(module, "ROOT", root):
                    with self.assertRaises(ValueError):
                        module.load_manifest("abc")


if __name__ == "__main__":
    unittest.main()
