"""B4 trusted REST GET source binding, no writes, fail-closed cases."""
import sys
import unittest
from unittest.mock import patch
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_b4_github_readonly import collect_owner_evidence, collect_comment_history, review_registration

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

    def test_composed_review_is_inert_and_replay_safe(self):
        import controller_b4_github_readonly as mod
        decision = {
            "status": "READY_FOR_REVIEW", "annotation_permitted": False,
            "writes_performed": False,
            "identity": {"run_id": 17, "release_sha": "a" * 40,
                         "release_id": "release-demo"},
            "owner_comment_id": 11, "owner_issue": 7}
        args = dict(issue_number=7, comment_id=11, token="read-token",
                    expected_owner="pecharko", evidence={},
                    ovh_attestation={}, run_id=17,
                    release_sha="a" * 40, release_id="release-demo")
        with patch.object(mod, "review_owner_with_github", return_value=decision):
            first = review_registration(**args,
                read_json=lambda path, token: [])
            self.assertEqual(first["status"], "READY_FOR_MANUAL_REVIEW")
            self.assertFalse(first["write_permitted"])
            self.assertFalse(first["write_executed"])
            duplicate = review_registration(**args, read_json=lambda path, token: [
                {**COMMENT, "body": first["proposed_body"]}])
            self.assertEqual(duplicate["status"], "ALREADY_RECORDED")
            with self.assertRaises(ValueError):
                review_registration(**args,
                    read_json=lambda path, token: [COMMENT] * 100)

    def test_incomplete_owner_review_prevents_history_fetch(self):
        import controller_b4_github_readonly as mod
        with patch.object(mod, "review_owner_with_github",
                          return_value={"status": "BLOCKED"}):
            result = review_registration(
                issue_number=7, comment_id=11, token="read-token",
                expected_owner="pecharko", evidence={},
                ovh_attestation={}, run_id=17,
                release_sha="a" * 40, release_id="release-demo",
                read_json=lambda *a: self.fail("comment history fetched"))
        self.assertEqual(result["status"], "BLOCKED")
        self.assertFalse(result["write_permitted"])

    def test_invalid_id_avoids_network(self):
        for issue, comment in ((True, 11), (7, True), (0, 11)):
            with self.assertRaises(ValueError):
                collect_owner_evidence(issue, comment, "token",
                    read_json=lambda *a: self.fail("GET called on invalid identity"))


if __name__ == "__main__":
    unittest.main()
