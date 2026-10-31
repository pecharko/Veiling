import unittest
from scripts.phase_c_c2_owner_gate import (
    review_owner_scope, HEAD, BRANCH, NONCE, TARGET,
)


def record():
    return dict(repository="pecharko/Veiling", pr=426, expected_head=HEAD,
                target_ref=BRANCH, nonce_ref=NONCE,
                candidate_oid="a"*40, candidate_parent=HEAD,
                candidate_paths=[TARGET], issue=427,
                approval_id="427", owner="pecharko",
                authorized_operation="atomic-nonce-and-sandbox-cas")


class OwnerGateTests(unittest.TestCase):
    def test_structurally_valid_is_never_execution_authority(self):
        response = review_owner_scope(record())
        self.assertEqual(response["decision"], "REVIEW_ONLY")
        for name in ("can_execute", "may_change_permissions", "may_retry", "may_deploy"):
            self.assertFalse(response[name])

    def test_fail_closed_on_any_scope_change(self):
        changes = {"repository": "someone/other", "pr": True,
                   "expected_head": "b"*40, "target_ref": "refs/heads/main",
                   "nonce_ref": "refs/tags/elsewhere", "candidate_oid": HEAD,
                   "candidate_parent": "b"*40, "candidate_paths": ["README.md"],
                   "issue": True, "approval_id": "invalid",
                   "owner": "other", "authorized_operation": "delete-branch"}
        for key, value in changes.items():
            with self.subTest(key=key):
                data = record()
                data[key] = value
                self.assertEqual(review_owner_scope(data)["decision"], "DENY")

    def test_unknown_or_missing_fields_deny(self):
        data = record()
        data["write_enabled"] = True
        self.assertEqual(review_owner_scope(data)["decision"], "DENY")
        data = record()
        del data["owner"]
        self.assertEqual(review_owner_scope(data)["decision"], "DENY")


if __name__ == "__main__":
    unittest.main()
