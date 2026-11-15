"""Isolated offline tests; fake local Git repo and downloaded files only."""
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from release_b_local_readback_report import TARGETS, compare


class LocalReadbackTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        root = Path(self.temp.name)
        self.repo, self.downloads = root / "repo", root / "downloads"
        self.repo.mkdir()
        self.downloads.mkdir()
        subprocess.run(["git", "-C", str(self.repo), "init", "-q"], check=True)
        for path in TARGETS:
            data = ("fixture:" + path).encode()
            for base in (self.repo, self.downloads):
                dest = base / path
                dest.parent.mkdir(parents=True, exist_ok=True)
                dest.write_bytes(data)
        subprocess.run(["git", "-C", str(self.repo), "add", "--", *TARGETS], check=True)
        subprocess.run(["git", "-C", str(self.repo), "-c", "user.name=Fixture",
                        "-c", "user.email=fixture@example.invalid",
                        "commit", "-qm", "fixture"], check=True)
        self.commit = subprocess.check_output(
            ["git", "-C", str(self.repo), "rev-parse", "HEAD"], text=True).strip()

    def test_all_four_match_but_evidence_stays_open(self):
        result = compare(self.downloads, self.repo, self.commit)
        self.assertEqual(result["comparison"], "MATCH")
        self.assertEqual(len(result["files"]), 4)
        self.assertEqual(result["independent_ovh_readback"], "PENDING")
        self.assertEqual(result["release_administration"], "OPEN")
        self.assertFalse(result["historical_release_readback_proven"])
        self.assertFalse(result["download_origin_authenticated"])
        self.assertFalse(result["separate_read_only_sftp_identity_verified"])

    def test_modified_download_fails_match(self):
        (self.downloads / TARGETS[0]).write_bytes(b"changed")
        result = compare(self.downloads, self.repo, self.commit)
        self.assertEqual(result["comparison"], "MISMATCH")
        self.assertFalse(result["files"][0]["match"])

    def test_commit_not_worktree_is_reference(self):
        (self.repo / TARGETS[0]).write_bytes(b"modified worktree")
        self.assertEqual(compare(self.downloads, self.repo, self.commit)["comparison"], "MATCH")

    def test_reject_unavailable_or_malformed_commit(self):
        for invalid in ("main", "a" * 39, "g" * 40, "b" * 40):
            with self.subTest(invalid=invalid), self.assertRaises(ValueError):
                compare(self.downloads, self.repo, invalid)

    def test_reject_missing_empty_or_symlink_download(self):
        dest = self.downloads / TARGETS[1]
        dest.unlink()
        with self.assertRaises((OSError, ValueError)):
            compare(self.downloads, self.repo, self.commit)
        dest.write_bytes(b"")
        with self.assertRaises(ValueError):
            compare(self.downloads, self.repo, self.commit)
        dest.unlink()
        dest.symlink_to(self.repo / TARGETS[1])
        with self.assertRaises(ValueError):
            compare(self.downloads, self.repo, self.commit)

    def test_reject_symlinked_parent(self):
        parent = self.downloads / "private"
        (parent / "lot_sellers.php").unlink()
        parent.rmdir()
        parent.symlink_to(self.repo / "private", target_is_directory=True)
        with self.assertRaises(ValueError):
            compare(self.downloads, self.repo, self.commit)


if __name__ == "__main__":
    unittest.main()
