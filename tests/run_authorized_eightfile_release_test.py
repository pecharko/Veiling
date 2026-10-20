#!/usr/bin/env python3
"""Release entry boundaries are tested without SFTP or remote writes."""
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from run_authorized_eightfile_release import run_authorized_release


class ReleaseRunnerTests(unittest.TestCase):
    @patch("run_authorized_eightfile_release.source_manifest")
    def test_no_release_consent_never_reads_or_connects(self, manifest):
        with self.assertRaises(PermissionError):
            run_authorized_release(
                release_sha="a" * 40, downloaded_rollback_zip=b"archive",
                independently_authorized=False
            )
        manifest.assert_not_called()

    @patch("run_authorized_eightfile_release.verify_downloaded_code_backup")
    @patch("run_authorized_eightfile_release.verify_last_deployed_baseline")
    @patch("run_authorized_eightfile_release.source_manifest")
    def test_invalid_backup_blocks_before_remote_connect(
        self, manifest, baseline, verify
    ):
        manifest.return_value = {"release_sha": "a" * 40, "files": []}
        baseline.return_value = {}
        verify.side_effect = RuntimeError("Artifact corrupted")
        with self.assertRaisesRegex(RuntimeError, "Artifact corrupted"):
            run_authorized_release(
                release_sha="a" * 40, downloaded_rollback_zip=b"invalid",
                independently_authorized=True
            )
        verify.assert_called_once()


if __name__ == "__main__":
    unittest.main()
