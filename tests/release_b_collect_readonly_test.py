"""Offline SFTP configuration security gates; no network involved."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from release_b_collect_readonly import config, preflight, read_from_independent_account

ENV = {
    "OVH_READONLY_SFTP_HOST": "sftp.example.test",
    "OVH_READONLY_SFTP_USER": "limited",
    "OVH_READONLY_SFTP_PASSWORD": "fixture-only",
    "OVH_READONLY_SFTP_KNOWN_HOSTS": "sftp.example.test ssh-ed25519 AAAAfixtureOnly",
    "OVH_READONLY_SFTP_BASE": "/www/site",
}

RUN = {"id": 123, "name": "Shared OVH manifest release with rollback",
       "path": ".github/workflows/shared-ovh-release.yml",
       "repository": {"full_name": "pecharko/Veiling"},
       "event": "workflow_dispatch", "head_branch": "main",
       "head_sha": "a"*40, "status": "completed", "conclusion": "success"}
UPLOAD = {"schema": 1, "release_id": "release-example", "release_sha": "a"*40,
          "run_id": 123, "status": "VERIFIED", "rollback_artifact": None,
          "browser_acceptance": "PENDING",
          "files": [{"path": "catalogus-public/asset.css",
                     "source_sha256": "b"*64, "baseline_sha256": "c"*64,
                     "readback_sha256": "b"*64}]}
MANIFEST = {"schema": 1, "files": [{"path": "catalogus-public/asset.css",
             "source_sha256": "b"*64, "remote_sha256": "c"*64}]}

class RunBindingTests(unittest.TestCase):
    def test_exact_run_binding_passes_but_does_not_authenticate_provenance(self):
        result = preflight(RUN, UPLOAD, MANIFEST)
        self.assertEqual(result["status"], "UPLOADER_ARTIFACT_CONTENT_CONSISTENT_ONLY")
        self.assertFalse(result["provenance_authenticated_by_caller"])
        self.assertEqual(result["independent_ovh_readback"], "PENDING")

    def test_wrong_run_never_connects(self):
        for modified in ({"id": 124}, {"head_sha": "d"*40},
                         {"event": "pull_request"}, {"head_branch": "preview/ui"},
                         {"conclusion": "failure"}, {"path": "other.yml"},
                         {"repository": {"full_name": "other/repo"}},
                         {"name": "Snapshot v8 catalog-only protected two-file release"}):
            with self.subTest(modified=modified), self.assertRaises(ValueError):
                read_from_independent_account(ENV, {**RUN, **modified}, UPLOAD,
                                             "collection-20261115-01", MANIFEST)

    def test_missing_manifest_or_unauthenticated_data_fails_closed(self):
        for manifest in ({}, {**MANIFEST, "files": []},
                         {"schema": 1, "files": [{**MANIFEST["files"][0], "source_sha256": "d"*64}]}):
            with self.subTest(manifest=manifest), self.assertRaises(ValueError):
                read_from_independent_account(ENV, RUN, UPLOAD,
                                             "collection-20261115-01", manifest)

    def test_evidence_release_binding_failure(self):
        with self.assertRaises(ValueError):
            preflight(RUN, {**UPLOAD, "release_id": "other-release"}, MANIFEST)
        with self.assertRaises(ValueError):
            preflight(RUN, {**UPLOAD, "run_id": 456}, MANIFEST)

class ConfigTest(unittest.TestCase):
    def test_requires_separate_scoped_credentials(self):
        host, user, password, known, root = config(ENV)
        self.assertEqual(host, "sftp.example.test")
        self.assertEqual(root, "/www/site")
        self.assertEqual(user, "limited")

    def test_never_falls_back_to_uploader_credentials(self):
        with self.assertRaises(ValueError):
            config({"OVH_SFTP_HOST": "sftp.example.test",
                    "OVH_SFTP_USER": "uploader", "OVH_SFTP_PASSWORD": "test"})

    def test_reject_missing_pin_and_invalid_root(self):
        for key, value in (("OVH_READONLY_SFTP_KNOWN_HOSTS", ""),
                           ("OVH_READONLY_SFTP_KNOWN_HOSTS", "other.test ssh-ed25519 AAAA"),
                           ("OVH_READONLY_SFTP_BASE", "/"),
                           ("OVH_READONLY_SFTP_BASE", "/www/../etc"),
                           ("OVH_READONLY_SFTP_HOST", "-invalid")):
            with self.subTest(key=key), self.assertRaises(ValueError):
                config({**ENV, key: value})

if __name__ == "__main__":
    unittest.main()
