"""B4 trusted REST GET source binding, no writes, fail-closed cases."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_b4_github_readonly import collect_owner_evidence, collect_comment_history

BASE = "https://api.github.com/repos/pecharko/Veiling/issues/7"
ISSUE = {"number": 7, "repository_url": "https://api.github.com/repos/pecharko/Veiling"}
COMMENT = {"id": 11, "issue_url": BASE, "body": "acceptance"}


class B4GitHubReadTests(unittest.TestCase):
    def test_two_exact_reads_only(self):
        seen = []
        def read(path, token):
            seen.append(path)
            return ISSUE if path == "/issues/7" else COMMENT
        self.assertEqual(collect_owner_evidence(7, 11, "read-token", read_json=read),
                         (ISSUE, COMMENT))
        self.assertEqual(seen, ["/issues/7", "/issues/comments/11"])

    def test_wrong_issue_or_comment_fails_closed(self):
        for issue, comment in (({**ISSUE, "number": 8}, COMMENT),
                               (ISSUE, {**COMMENT, "id": 12}),
                               (ISSUE, {**COMMENT, "issue_url": BASE + "8"})):
            with self.subTest(comment=comment):
                def read(path, token):
                    return issue if path == "/issues/7" else comment
                with self.assertRaises(ValueError):
                    collect_owner_evidence(7, 11, "read-token", read_json=read)

    def test_comment_history_bounded_and_issue_bound(self):
        def read(path, token):
            self.assertEqual(path, "/issues/7/comments?per_page=100")
            return [COMMENT]
        self.assertEqual(collect_comment_history(7, "read-token", read_json=read),
                         [COMMENT])
        for comments in ([COMMENT] * 100,
                         [{**COMMENT, "issue_url": BASE + "8"}], None):
            with self.subTest(count=len(comments) if comments else 0):
                with self.assertRaises(ValueError):
                    collect_comment_history(7, "read-token",
                                            read_json=lambda *a: comments)

    def test_invalid_id_avoids_network(self):
        for issue, comment in ((True, 11), (7, True), (0, 11)):
            with self.assertRaises(ValueError):
                collect_owner_evidence(issue, comment, "token",
                    read_json=lambda *a: self.fail("GET called on invalid identity"))


if __name__ == "__main__":
    unittest.main()
