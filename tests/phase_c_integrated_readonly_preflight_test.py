import unittest
from scripts.phase_c_integrated_readonly_preflight import evaluate_integrated_readonly, inspect_proposal_offline

HEAD = "a" * 40
BLOB = "b" * 40
ROOT = "/repos/pecharko/Veiling"
ISSUE_URL = "https://api.github.com" + ROOT + "/issues/900"

def fixtures():
    response = {
        ROOT + "/pulls/901": {
            "number": 901, "state": "open", "merged": False,
            "base": {"repo": {"full_name": "pecharko/Veiling"}},
            "head": {"repo": {"full_name": "pecharko/Veiling"},
                     "ref": "test/phase-c-sandbox-one", "sha": HEAD}},
        ROOT + "/issues/900": {
            "state": "open", "id": 100, "url": ISSUE_URL,
            "repository_url": "https://api.github.com" + ROOT,
            "user": {"id": 343879284}},
        ROOT + "/issues/comments/101": {
            "id": 101, "issue_url": ISSUE_URL, "user": {"id": 343879284},
            "body": "PHASE-C-SANDBOX-ONCE pr=901 head=" + HEAD + " blob=" + BLOB},
        ROOT + "/contents/tests/phase_c_sandbox_test.php?ref=" + HEAD: {
            "sha": BLOB, "path": "tests/phase_c_sandbox_test.php", "type": "file"}
    }
    auth = {"issue_id": 100, "comment_id": 101, "used": False, "attempt": 0,
            "new_sha256": "c" * 64}
    snapshot = {"head_sha": HEAD, "blob_sha": BLOB,
                "target_path": "tests/phase_c_sandbox_test.php"}
    return response, auth, snapshot

class IntegratedTests(unittest.TestCase):
    def test_owner_evidence_is_not_enough_to_write(self):
        responses, auth, snapshot = fixtures()
        result = evaluate_integrated_readonly(responses.__getitem__, 901, 900, 101,
                                               auth, snapshot, {})
        self.assertEqual(result["stage"], "nonce")
        self.assertFalse(result["can_write"])
        self.assertFalse(result["can_merge"])
        self.assertFalse(result["can_dispatch"])

    def test_owner_impersonation_is_denied_before_nonce_stage(self):
        responses, auth, snapshot = fixtures()
        responses[ROOT + "/issues/comments/101"]["user"]["id"] = 999
        result = evaluate_integrated_readonly(responses.__getitem__, 901, 900, 101,
                                               auth, snapshot, {})
        self.assertEqual(result["stage"], "owner")
        self.assertFalse(result["verified"])

    def test_api_failure_is_denied(self):
        _, auth, snapshot = fixtures()
        def failing_reader(path):
            raise OSError("API unavailable")
        result = evaluate_integrated_readonly(failing_reader, 901, 900, 101, auth, snapshot, {})
        self.assertEqual(result["decision"], "DENY")

    def test_offline_advisory_never_grants_write(self):
        result = inspect_proposal_offline({}, {}, {})
        self.assertFalse(result["can_write"])
        self.assertFalse(result["can_dispatch"])

if __name__ == "__main__":
    unittest.main()
