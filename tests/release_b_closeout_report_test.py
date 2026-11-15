"""Read-only closeout report refuses to infer independent acceptance."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from release_b_closeout_report import report

RUN = {
    "id": 123, "name": "Shared OVH manifest release with rollback",
    "path": ".github/workflows/shared-ovh-release.yml",
    "repository": {"full_name": "pecharko/Veiling"},
    "event": "workflow_dispatch", "head_branch": "main", "head_sha": "a"*40,
    "status": "completed", "conclusion": "success",
}
ARTIFACTS = {"artifacts": [
    {"name": "shared-ovh-evidence-123", "expired": False},
    {"name": "shared-ovh-rollback-123", "expired": False},
]}

class CloseoutTest(unittest.TestCase):
    def test_success_never_claims_release_accepted(self):
        text = report(RUN, ARTIFACTS)
        self.assertIn("**OPEN**", text)
        self.assertIn("OVH-readback | PENDING", text)
        self.assertIn("browseracceptatie | PENDING", text)
        self.assertNotIn("**CLOSED**", text)

    def test_missing_rollback_stays_open(self):
        text = report(RUN, {"artifacts": [ARTIFACTS["artifacts"][0]]})
        self.assertIn("Rollbackartifact aanwezig | Nee", text)
        self.assertIn("**OPEN**", text)

    def test_failed_run_stays_open(self):
        text = report({**RUN, "conclusion": "failure"}, ARTIFACTS)
        self.assertIn("Mislukt of onvolledig", text)
        self.assertIn("**OPEN**", text)

    def test_other_workflow_rejected(self):
        with self.assertRaises(ValueError):
            report({**RUN, "path": ".github/workflows/not-deploy.yml"}, ARTIFACTS)

if __name__ == "__main__":
    unittest.main()
