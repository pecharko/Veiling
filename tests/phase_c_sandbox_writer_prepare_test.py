import hashlib
import unittest
from scripts.phase_c_sandbox_writer_prepare import verify_prepared_transaction


def case():
    old = "<?php\nreturn flase;\n"
    new = "<?php\nreturn false;\n"
    return dict(repo="pecharko/Veiling", owner_login="pecharko",
        issue_author="pecharko", comment_author="pecharko", issue_open=True,
        pr_branch="test/phase-c-sandbox-20261029", pr_head="a"*40,
        approved_head="a"*40, file_path="tests/phase_c_sandbox_test.php",
        blob_sha="b"*40, approved_blob="b"*40,
        old_content=old, new_content=new, attempt_count=0)


class SandboxWriterPreparationTests(unittest.TestCase):
    def test_valid_digests_remain_non_writing(self):
        data=case()
        digest=hashlib.sha256(data["new_content"].encode()).hexdigest()
        result=verify_prepared_transaction(data,digest)
        self.assertEqual(result["decision"],"READY_FOR_SEPARATE_AUTHORIZATION")
        self.assertIs(result["write_allowed"],False)
        self.assertIs(result["merge_allowed"],False)
        self.assertIs(result["deployment_allowed"],False)

    def test_wrong_digest_denied(self):
        data=case()
        self.assertEqual(verify_prepared_transaction(data,"0"*64)["decision"],"DENY")

    def test_untrusted_identity_denied(self):
        data=case()
        data["comment_author"]="attacker"
        self.assertEqual(verify_prepared_transaction(data,"0"*64)["decision"],"DENY")


if __name__=="__main__":
    unittest.main()
