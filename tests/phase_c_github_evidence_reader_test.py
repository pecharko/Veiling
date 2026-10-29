import unittest
from scripts.phase_c_github_evidence_reader import collect_and_check

HEAD = "a" * 40
BLOB = "b" * 40
ROOT = "/repos/pecharko/Veiling"
ISSUE_URL = "https://api.github.com" + ROOT + "/issues/900"


def responses():
    return {
        ROOT + "/pulls/901": {
            "number": 901, "state": "open", "merged": False,
            "base": {"repo": {"full_name": "pecharko/Veiling"}},
            "head": {"repo": {"full_name": "pecharko/Veiling"},
                     "ref": "test/phase-c-sandbox-20261029", "sha": HEAD}},
        ROOT + "/issues/900": {
            "state": "open", "id": 100, "url": ISSUE_URL,
            "repository_url": "https://api.github.com" + ROOT,
            "user": {"id": 343879284}},
        ROOT + "/issues/comments/101": {
            "id": 101, "issue_url": ISSUE_URL, "user": {"id": 343879284},
            "body": "PHASE-C-SANDBOX-ONCE pr=901 head=" + HEAD + " blob=" + BLOB},
        ROOT + "/contents/tests/phase_c_sandbox_test.php?ref=" + HEAD: {
            "sha": BLOB, "path": "tests/phase_c_sandbox_test.php", "type": "file"},
    }


class EvidenceReaderTests(unittest.TestCase):
    def test_only_four_read_requests_and_no_write_permission(self):
        response = responses()
        called = []
        def get(path):
            called.append(path)
            return response[path]
        result = collect_and_check(get, 901, 900, 101,
            {"issue_id": 100, "comment_id": 101, "used": False, "attempt": 0})
        self.assertTrue(result["verified"])
        self.assertIs(result["write_allowed"], False)
        self.assertEqual(called, list(response))

    def test_fail_closed(self):
        for get in (lambda path: None, lambda path: (_ for _ in ()).throw(OSError("down"))):
            self.assertFalse(collect_and_check(get, 901, 900, 101, {})["verified"])
        self.assertFalse(collect_and_check(lambda path: {}, False, 900, 101, {})["verified"])
        changed = responses()
        changed[ROOT + "/issues/comments/101"]["user"]["id"] = 123
        self.assertFalse(collect_and_check(changed.__getitem__, 901, 900, 101,
            {"issue_id": 100, "comment_id": 101, "used": False, "attempt": 0})["verified"])


if __name__ == "__main__":
    unittest.main()
