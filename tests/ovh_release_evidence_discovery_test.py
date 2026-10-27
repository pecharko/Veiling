"""Offline tests for GitHub evidence discovery. No network used."""
import io
import json
import sys
import unittest
from pathlib import Path
from unittest.mock import patch
from zipfile import ZipFile
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
import ovh_release_evidence_discovery as discovery


class DiscoveryTests(unittest.TestCase):
    def test_single_evidence_member(self):
        buf = io.BytesIO()
        with ZipFile(buf, "w") as z:
            z.writestr("deployment-evidence.json", json.dumps({"status": "FAILED"}))
        self.assertEqual(discovery.read_evidence_zip(buf.getvalue())["status"], "FAILED")

    def test_reject_unexpected_zip_member(self):
        buf = io.BytesIO()
        with ZipFile(buf, "w") as z:
            z.writestr("untrusted.json", "{}")
        with self.assertRaises(ValueError):
            discovery.read_evidence_zip(buf.getvalue())

    def test_latest_success_is_not_silently_skipped_on_bad_evidence(self):
        response = {"workflow_runs": [{"id": 100, "status": "completed", "conclusion": "success"},
                                      {"id": 99, "status": "completed", "conclusion": "success"}]}
        with patch.object(discovery, "json_get", return_value=response), patch.object(
            discovery, "inspect_run", side_effect=ValueError("bad proof")
        ) as inspect:
            with self.assertRaises(ValueError):
                discovery.latest_review("dummy")
        self.assertEqual(inspect.call_count, 1)

    def test_no_successful_run(self):
        with patch.object(discovery, "json_get", return_value={"workflow_runs": []}):
            with self.assertRaises(ValueError):
                discovery.latest_review("dummy")

if __name__ == "__main__":
    unittest.main()
