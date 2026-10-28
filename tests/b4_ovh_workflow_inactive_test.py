"""B4 security contract: PR validation isolated from opt-in readback."""
import pathlib
import re
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
        self.assertIn("verify_release_run(int(os.environ", text)
        self.assertIn('test "$(git rev-parse HEAD)" = "$RELEASE_SHA"', text)
        self.assertIn("persist-credentials: false", text)
        self.assertNotIn("scripts/shared_ovh_release.py deploy", text)
        self.assertNotIn("issues: write", text)
        self.assertNotIn("contents: write", text)
        self.assertNotIn("actions: write", text)

    def test_no_production_secrets_in_pr_validation_job(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        jobs = text.split("  readback:", 1)
        self.assertEqual(len(jobs), 2)
        self.assertNotIn("secrets.", jobs[0])
        self.assertNotIn("environment: production", jobs[0])
        self.assertIn("OVH_SFTP_PASSWORD:", jobs[1])
        self.assertIn("controller_b4_ovh_readback.py", jobs[1])
        self.assertIn("controller_b4_ovh_readback_test.py", jobs[0])

if __name__ == "__main__":
    unittest.main()
