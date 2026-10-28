"""B4 security contract: PR validation isolated from opt-in readback."""
import pathlib
import re
import subprocess
import tempfile
import unittest

WORKFLOW = pathlib.Path(__file__).resolve().parents[1] / ".github/workflows/b4-ovh-verification.yml"

class B4WorkflowSecurityTests(unittest.TestCase):
    def test_dispatch_cannot_run_on_pr_or_without_explicit_confirmation(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("workflow_dispatch:", text)
        self.assertIn("pull_request:", text)
        self.assertNotIn("workflow_run:", text)
        self.assertNotIn("schedule:", text)
        self.assertNotIn("push:", text)
        self.assertIn("if: github.event_name == 'pull_request'", text)
        self.assertIn("if: github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main'", text)
        self.assertIn('test "$CONFIRMATION" = "VERIFY-$RELEASE_ID-$RELEASE_SHA-$RELEASE_RUN_ID"', text)
        self.assertIn("environment: production", text)

    def test_release_authentication_before_immutable_checkout_and_network(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        auth = text.index("Authenticate release run before executing pinned release code")
        checkout = text.index("ref: ${{ inputs.release_sha }}")
        install = text.index("Pinned readback SFTP dependency")
        readback = text.index("Observe OVH file hashes without writes")
        self.assertLess(auth, checkout)
        self.assertLess(checkout, install)
        self.assertLess(install, readback)
        self.assertIn("ref: ${"+"{ github.sha }}", text)
        self.assertIn('test "$(git rev-parse HEAD)" = "$GITHUB_SHA"', text)
        self.assertNotIn("ref: main", text)
        self.assertIn("verify_release_run(int(os.environ", text)
        self.assertIn('"$(git -C release-data rev-parse HEAD)" != "$RELEASE_SHA"', text)
        self.assertIn("persist-credentials: false", text)
        self.assertNotIn("scripts/shared_ovh_release.py deploy", text)
        self.assertNotIn("issues: write", text)
        self.assertNotIn("contents: write", text)
        self.assertNotIn("actions: write", text)

    def test_nested_release_checkout_only_is_exempted(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("git status --porcelain --untracked-files=normal", text)
        self.assertIn("grep -v '^?? release-data/$'", text)
        with tempfile.TemporaryDirectory() as td:
            root = pathlib.Path(td)
            subprocess.run(["git", "init", "-q", str(root)], check=True)
            (root / "release-data").mkdir()
            subprocess.run(["git", "init", "-q", str(root / "release-data")], check=True)
            (root / "release-data" / "manifest.json").write_text("{}")
            def unexpected():
                proc = subprocess.run(["git", "status", "--porcelain", "--untracked-files=normal"], cwd=root, check=True, text=True, capture_output=True)
                return [line for line in proc.stdout.splitlines() if line != "?? release-data/"]
            self.assertEqual(unexpected(), [])
            (root / "unreviewed.txt").write_text("unexpected")
            self.assertIn("?? unreviewed.txt", unexpected())

    def test_checkout_checkpoints_fail_closed_without_sftp(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        for checkpoint in (1, 2, 3):
            self.assertIn(f"B4 checkout checkpoint {checkpoint} failed:", text)
            self.assertIn(f"B4 checkout checkpoint {checkpoint} OK:", text)
        self.assertIn("git -C release-data status --porcelain --untracked-files=all", text)
        self.assertIn("git status --porcelain --untracked-files=normal", text)
        self.assertIn("grep -v '^?? release-data/$'", text)
        self.assertLess(text.index("B4 checkout checkpoint 3 OK"), text.index("Pinned readback SFTP dependency"))

    def test_no_production_secrets_in_pr_validation_job(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        jobs = text.split("  readback:", 1)
        self.assertEqual(len(jobs), 2)
        self.assertNotIn("secrets.", jobs[0])
        self.assertNotIn("environment: production", jobs[0])
        self.assertIn("OVH_SFTP_PASSWORD:", jobs[1])
        self.assertIn("path: release-data", jobs[1])
        self.assertIn("--release-checkout release-data", jobs[1])
        self.assertNotIn("OVH_SFTP_PASSWORD:", jobs[1].split("    steps:", 1)[0])
        self.assertNotIn("ref: ${{ inputs.release_sha }}", jobs[1].split("    steps:", 1)[0])
        self.assertLess(jobs[1].index("name: Observe OVH file hashes without writes"), jobs[1].index("OVH_SFTP_PASSWORD:"))
        self.assertIn("controller_b4_ovh_readback.py", jobs[1])
        self.assertIn("controller_b4_ovh_readback_test.py", jobs[0])

if __name__ == "__main__":
    unittest.main()
