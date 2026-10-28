"""B2: artifact provenance and expiry must never become independent remote proof."""
import sys
import unittest
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_readonly_reconciliation import artifact_retention

NOW = datetime(2026, 10, 28, tzinfo=timezone.utc)


def artifacts(expires="2026-11-02T00:00:00Z", created="2026-10-25T00:00:00Z"):
    return [{"name": prefix + "42", "id": n, "expired": False,
             "workflow_run": {"id": 42}, "created_at": created, "expires_at": expires}
            for n, prefix in ((11, "shared-ovh-evidence-"), (12, "shared-ovh-rollback-"))]


class RetentionTests(unittest.TestCase):
    def test_metadata_is_observed_not_independent_proof(self):
        result = artifact_retention(artifacts(), 42, now=NOW)
        self.assertEqual(result["status"], "OBSERVED")
        self.assertFalse(result["retention_7_days_independently_verified"])
        self.assertEqual([x["id"] for x in result["artifacts"]], [11, 12])
        self.assertTrue(all(x["source"] == "github_actions_artifact_api"
                            for x in result["artifacts"]))

    def test_missing_expired_duplicate_or_foreign_artifact(self):
        cases = [artifacts()[:1], artifacts() + artifacts()[:1],
                 [{**a, "expired": True} for a in artifacts()],
                 [{**a, "workflow_run": {"id": 43}} for a in artifacts()]]
        for items in cases:
            with self.subTest(items=items):
                result = artifact_retention(items, 42, now=NOW)
                self.assertEqual(result["status"], "INCOMPLETE")

    def test_expiry_insufficient_or_missing(self):
        for items in (artifacts(expires="2026-10-30T00:00:00Z"),
                      artifacts(expires="2026-10-27T00:00:00Z"),
                      [{k: v for k, v in a.items() if k != "expires_at"}
                       for a in artifacts()]):
            with self.subTest(items=items):
                self.assertEqual(artifact_retention(items, 42, now=NOW)["status"],
                                 "INCOMPLETE")


if __name__ == "__main__":
    unittest.main()
