"""Security gate: the staged B4 workflow must never access production."""
import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/b4-ovh-verification.yml"


class B4InactiveWorkflowTests(unittest.TestCase):
    def test_no_runtime_or_privileged_hooks(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        active = "\n".join(line for line in text.splitlines()
                           if not line.lstrip().startswith("#"))
        self.assertIn("pull_request:", active)
        self.assertNotIn("workflow_dispatch:", active)
        self.assertNotIn("workflow_run:", active)
        for forbidden in ("environment:", "secrets.", "OVH_SFTP_", "deploy ",
                          "scripts/shared_ovh_release.py deploy",
                          "issues: write", "contents: write", "actions: write",
                          "schedule:", "push:"):
            self.assertNotIn(forbidden, active)
        self.assertEqual(re.findall(r"^  [a-z][a-z-]+:$", active, re.M),
                         ["  validate-inactive-contract:"])

    def test_only_offline_ci_invocations(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("controller_b4_ovh_readback_test.py", text)
        self.assertIn("b4_ovh_workflow_inactive_test.py", text)
        self.assertIn("persist-credentials: false", text)


if __name__ == "__main__":
    unittest.main()
