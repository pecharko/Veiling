import unittest
from scripts.phase_c_sandbox_transaction import plan_sandbox_repair


def evidence():
    old = "<?php\nfunction phase_c_sandbox_example(): bool { return flase; }\n"
    return dict(
        repo="pecharko/Veiling", owner_login="pecharko",
        issue_author="pecharko", comment_author="pecharko", issue_open=True,
        pr_branch="test/phase-c-sandbox-20261029", pr_head="a" * 40,
        approved_head="a" * 40, file_path="tests/phase_c_sandbox_test.php",
        blob_sha="b" * 40, approved_blob="b" * 40,
        old_content=old, new_content=old.replace("flase", "false"), attempt_count=0,
    )


class SandboxTransactionTests(unittest.TestCase):
    def test_valid_fixture_is_still_proposal_only(self):
        r = plan_sandbox_repair(evidence())
        self.assertEqual(r["decision"], "PROPOSAL_ONLY")
        self.assertFalse(r["write_allowed"])
        self.assertEqual(len(r["proposed_sha256"]), 64)

    def test_all_bypasses_denied(self):
        for key, bad in (
            ("repo", "other/repo"), ("issue_author", "attacker"),
            ("comment_author", "attacker"), ("issue_open", False),
            ("pr_branch", "main"), ("pr_branch", "feature/a"),
            ("approved_head", "c" * 40), ("approved_blob", "d" * 40),
            ("file_path", "private/config.php"), ("attempt_count", 1),
            ("attempt_count", False), ("new_content", "<?php\nreturn true;"),
            ("old_content", "<?php\nreturn flase; return flase;"),
        ):
            with self.subTest(key=key, bad=bad):
                e = evidence()
                e[key] = bad
                r = plan_sandbox_repair(e)
                self.assertEqual(r["decision"], "DENY")
                self.assertFalse(r["write_allowed"])


if __name__ == "__main__":
    unittest.main()
