"""Offline adversarial tests for non-authorizing PR475 receipt checks."""
import copy
import unittest
from scripts import phase_c_new_offline_receipt as reader
from scripts import phase_c_new_trusted_writer as scope


def fixture():
    cand = "a" * 40
    nonce_ref = "refs/tags/phase-c-once-issue-480"
    receipt = {
        "repository": scope.REPO, "repository_node_id": scope.REPO_NODE,
        "nonce_ref": nonce_ref,
        "branch": {"ref": scope.BRANCH, "sha": cand},
        "nonce": {"ref": nonce_ref, "sha": scope.HEAD, "type": "commit"},
        "candidate_commit": {"sha": cand, "parents": [scope.HEAD]},
        "pull_request": {"number": scope.PR, "draft": True,
                         "merged": False, "state": "open", "head": cand},
        "file": {"path": scope.TARGET, "blob": scope.NEW_BLOB,
                 "content": scope.deterministic_candidate(scope.ORIGINAL)},
        "compare": {"base": scope.HEAD, "head": cand,
                    "ahead_by": 1, "behind_by": 0,
                    "files": [{"path": scope.TARGET, "status": "modified",
                               "additions": 1, "deletions": 1,
                               "old_blob": scope.OLD_BLOB,
                               "new_blob": scope.NEW_BLOB}]},
    }
    return receipt, cand


class OfflineReceiptTests(unittest.TestCase):
    def test_complete_receipt_is_still_not_execution_authority(self):
        receipt, sha = fixture()
        verdict = reader.review_receipt(receipt, candidate_oid=sha)
        self.assertEqual(verdict["decision"], "OFFLINE_RECEIPT_CONSISTENT")
        for flag in ("write_allowed", "network_allowed", "retry_allowed",
                     "activation_allowed"):
            self.assertFalse(verdict[flag])

    def test_tampered_evidence_denied(self):
        cases = [
            ("repository", None, "fake/repo"),
            ("repository_node_id", None, "fake"),
            ("branch", "sha", "b"*40),
            ("nonce", "sha", "b"*40),
            ("nonce", "type", "tag"),
            ("candidate_commit", "parents", []),
            ("candidate_commit", "sha", "b"*40),
            ("pull_request", "draft", False),
            ("pull_request", "merged", True),
            ("pull_request", "state", "closed"),
            ("pull_request", "head", "b"*40),
            ("file", "blob", "b"*40),
            ("file", "content", b"corrupt"),
            ("compare", "ahead_by", 2),
            ("compare", "behind_by", 1),
            ("compare", "files", []),
            ("nonce_ref", None, "refs/tags/phase-c-once-issue-427"),
        ]
        for key, member, val in cases:
            with self.subTest(key=key, member=member):
                data, sha = fixture()
                if member is None:
                    data[key] = val
                else:
                    data[key][member] = val
                self.assertEqual(reader.review_receipt(data, candidate_oid=sha)["decision"], "DENY")

    def test_malformed_or_missing_receipts_denied(self):
        for data in (None, [], {}, {"candidate_commit": None}, {"nonce_ref": 12}):
            with self.subTest(data=data):
                self.assertEqual(reader.review_receipt(data, candidate_oid="a"*40)["decision"], "DENY")
        receipt, _ = fixture()
        for sha in ("", "nothex", "z"*40, scope.HEAD):
            self.assertEqual(reader.review_receipt(receipt, candidate_oid=sha)["decision"], "DENY")

    def test_execution_remains_unconditionally_blocked(self):
        class Hostile:
            def __getattr__(self, _name):
                raise AssertionError("No access allowed")
        denied = reader.execute(token="secret", opener=Hostile(), enabled=True)
        self.assertEqual(denied["decision"], "DENY")
        self.assertFalse(denied["network_allowed"])
        self.assertFalse(denied["retry_allowed"])


if __name__ == "__main__":
    unittest.main()
