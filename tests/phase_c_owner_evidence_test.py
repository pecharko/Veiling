import unittest
from scripts.phase_c_owner_evidence import check_github_owner_evidence

HEAD = "a" * 40
BLOB = "b" * 40
ISSUE_URL = "https://api.github.com/repos/pecharko/Veiling/issues/900"

def fixture():
    pr = {"number": 901, "state": "open", "merged": False,
          "base": {"repo": {"full_name": "pecharko/Veiling"}},
          "head": {"repo": {"full_name": "pecharko/Veiling"},
                   "ref": "test/phase-c-sandbox-20261029", "sha": HEAD}}
    issue = {"state": "open", "url": ISSUE_URL, "id": 100,
             "repository_url": "https://api.github.com/repos/pecharko/Veiling",
             "user": {"id": 343879284}}
    comment = {"id": 101, "issue_url": ISSUE_URL,
               "user": {"id": 343879284},
               "body": "PHASE-C-SANDBOX-ONCE pr=901 head=" + HEAD + " blob=" + BLOB}
    auth = {"issue_id": 100, "comment_id": 101, "used": False, "attempt": 0}
    contents = {"sha": BLOB, "path": "tests/phase_c_sandbox_test.php", "type": "file"}
    return pr, issue, comment, auth, contents


class OwnerEvidenceTests(unittest.TestCase):
    def test_valid_evidence_never_issues_write_permission(self):
        result = check_github_owner_evidence(*fixture())
        self.assertTrue(result["verified"])
        self.assertFalse(result["write_allowed"])

    def test_sensitive_changes_refused(self):
        for item, key, value in (
            (0, "state", "closed"),
            (1, "state", "closed"),
            (2, "body", "go"),
            (3, "used", True),
            (3, "attempt", 1),
            (3, "attempt", False),
            (4, "path", "private/config.php"),
            (4, "sha", "invalid"),
        ):
            with self.subTest(item=item, key=key):
                case = list(fixture())
                case[item][key] = value
                result = check_github_owner_evidence(*case)
                self.assertFalse(result["verified"])
                self.assertFalse(result["write_allowed"])

    def test_forks_and_impersonation_refused(self):
        for item, child, key, value in (
            (0, "head", "repo", {"full_name": "attacker/fork"}),
            (0, "head", "ref", "main"),
            (1, "user", "id", 1000),
            (2, "user", "id", 1000),
        ):
            with self.subTest(item=item, key=key):
                case = list(fixture())
                case[item][child][key] = value
                result = check_github_owner_evidence(*case)
                self.assertFalse(result["verified"])

    def test_malformed_denied(self):
        self.assertFalse(check_github_owner_evidence(None, {}, {}, {}, {})["verified"])


if __name__ == "__main__":
    unittest.main()
