"""Adversarial offline tests for Phase C CAS simulation (no GitHub writes)."""
import hashlib
import unittest
from scripts.phase_c_cas_offline import simulate_cas_transaction

BEFORE = "<?php function test() { return flase; }"
AFTER = BEFORE.replace("return flase;", "return false;")


def fixtures():
    a = {"head_sha": "a" * 40, "blob_sha": "b" * 40,
         "target_path": "tests/phase_c_sandbox_test.php",
         "result_sha256": hashlib.sha256(AFTER.encode()).hexdigest()}
    s = {"head_sha": "a" * 40, "blob_sha": "b" * 40, "content": BEFORE}
    p = {"observed_head_sha": "a" * 40, "observed_blob_sha": "b" * 40,
         "path": "tests/phase_c_sandbox_test.php", "content": AFTER,
         "nonce_reserved_atomically": True}
    return a, s, p


class AdversarialCasTests(unittest.TestCase):
    def test_rejects_multiple_typos_and_added_lines(self):
        a, s, p = fixtures()
        s["content"] += "\nreturn flase;"
        self.assertFalse(simulate_cas_transaction(a, s, p)["accepted"])
        a, s, p = fixtures()
        p["content"] += "\n# unexpected addition"
        self.assertFalse(simulate_cas_transaction(a, s, p)["accepted"])

    def test_wrong_target_digest_and_malformed_heads(self):
        for key, value in (("head_sha", "z" * 40), ("blob_sha", None),
                           ("result_sha256", "0" * 64),
                           ("target_path", ".github/workflows/php-lint.yml")):
            with self.subTest(key=key):
                a, s, p = fixtures()
                a[key] = value
                self.assertFalse(simulate_cas_transaction(a, s, p)["accepted"])

    def test_never_authorizes_writes_even_on_valid_evidence(self):
        r = simulate_cas_transaction(*fixtures())
        self.assertTrue(r["accepted"])
        self.assertIs(r["can_write"], False)
        self.assertIs(r["can_merge"], False)
        self.assertIs(r["can_dispatch"], False)


if __name__ == "__main__":
    unittest.main()
