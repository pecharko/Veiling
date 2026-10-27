"""Read-only review tests with synthetic evidence; no GitHub or OVH access."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from ovh_release_evidence_review import verify

SHA = "b" * 40
HASH = "a" * 64
BASE = "c" * 64
RUN = {"id": 123, "head_sha": SHA, "event": "workflow_dispatch",
       "head_branch": "main", "path": ".github/workflows/shared-ovh-release.yml@main",
       "status": "completed", "conclusion": "success"}
EVIDENCE = {"schema": 1, "release_id": "test-abc", "release_sha": SHA,
            "run_id": 123, "status": "VERIFIED", "rollback_artifact": None,
            "browser_acceptance": "PENDING",
            "files": [{"path": "catalogus-public/admin/admin.css",
                       "source_sha256": HASH, "baseline_sha256": BASE,
                       "readback_sha256": HASH}]}
MANIFEST = {"schema": 1, "files": [{"path": "catalogus-public/admin/admin.css",
                                   "source_sha256": HASH, "remote_sha256": BASE}]}
ARTIFACTS = [{"id": 10, "name": "shared-ovh-evidence-123", "expired": False},
             {"id": 11, "name": "shared-ovh-rollback-123", "expired": False}]


class ReviewTests(unittest.TestCase):
    def test_accept_realistic_verified_snapshot(self):
        self.assertEqual(verify(RUN, EVIDENCE, MANIFEST, ARTIFACTS)["status"], "TECHNICALLY_VERIFIED")

    def test_accept_ref_heads_main_and_legacy_workflow_paths(self):
        allowed = (
            ".github/workflows/shared-ovh-release.yml@refs/heads/main",
            "pecharko/Veiling/.github/workflows/shared-ovh-release.yml@main",
            "pecharko/Veiling/.github/workflows/shared-ovh-release.yml@refs/heads/main",
            ".github/workflows/shared-ovh-release.yml",
        )
        for path in allowed:
            with self.subTest(path=path):
                result = verify({**RUN, "path": path}, EVIDENCE, MANIFEST, ARTIFACTS)
                self.assertEqual(result["status"], "TECHNICALLY_VERIFIED")

    def test_reject_wrong_workflow_ref_path_and_nonstring(self):
        rejected = (
            ".github/workflows/shared-ovh-release.yml@feature",
            ".github/workflows/shared-ovh-release.yml@refs/heads/staging",
            ".github/workflows/other-release.yml@main",
            "other/repo/.github/workflows/shared-ovh-release.yml@main",
            "attack.github/workflows/shared-ovh-release.yml@main",
            ".github/workflows/shared-ovh-release.yml@main/evil",
            None,
        )
        for path in rejected:
            with self.subTest(path=path), self.assertRaises(ValueError):
                verify({**RUN, "path": path}, EVIDENCE, MANIFEST, ARTIFACTS)

    def test_reject_user_report_without_completed_run(self):
        with self.assertRaises(ValueError):
            verify({**RUN, "conclusion": "failure"}, EVIDENCE, MANIFEST, ARTIFACTS)

    def test_reject_other_commit(self):
        with self.assertRaises(ValueError):
            verify({**RUN, "head_sha": "d"*40}, EVIDENCE, MANIFEST, ARTIFACTS)

    def test_reject_wrong_manifest_hash(self):
        with self.assertRaises(ValueError):
            verify(RUN, EVIDENCE, {**MANIFEST, "files": [
                {**MANIFEST["files"][0], "source_sha256": "d"*64}
            ]}, ARTIFACTS)

    def test_reject_missing_rollback_artifact(self):
        with self.assertRaises(ValueError):
            verify(RUN, EVIDENCE, MANIFEST, ARTIFACTS[:1])

    def test_reject_expired_artifact(self):
        with self.assertRaises(ValueError):
            verify(RUN, EVIDENCE, MANIFEST, [
                ARTIFACTS[0], {**ARTIFACTS[1], "expired": True}
            ])

    def test_reject_unverified_status(self):
        with self.assertRaises(ValueError):
            verify(RUN, {**EVIDENCE, "status": "ROLLED_BACK"}, MANIFEST, ARTIFACTS)


if __name__ == "__main__":
    unittest.main()
