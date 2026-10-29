"""Offline tests of inert Phase C transport and untrusted proof claims."""
import unittest
from dataclasses import replace

from scripts.phase_c_disabled_writer import Transaction
from scripts.phase_c_inert_transport import ReadOnlyProof, assess_proof, transport_request


class TransportContractTests(unittest.TestCase):
    def evidence(self):
        t = Transaction()
        return ReadOnlyProof(t.head, t.blob, True, True, True, True, True, True, True)

    def test_even_fully_positive_evidence_cannot_authorize(self):
        r = assess_proof(self.evidence(), Transaction())
        self.assertEqual(r["decision"], "DENY")
        self.assertFalse(r["write_allowed"])
        self.assertFalse(r["retry_allowed"])
        self.assertTrue(r["proof_complete_in_simulation"])

    def test_each_missing_or_malformed_claim_is_denied(self):
        names = ("owner_comment_verified", "actor_identity_verified",
                 "permission_scope_verified", "tag_rules_verified",
                 "branch_rules_verified", "nonce_absence_verified_atomically",
                 "expected_branch_update_cas_verified")
        for name in names:
            for bad in (False, None, 1, "true"):
                with self.subTest(name=name, bad=bad):
                    p = replace(self.evidence(), **{name: bad})
                    r = assess_proof(p, Transaction())
                    self.assertFalse(r["write_allowed"])
                    self.assertEqual(r["reason"], "Unproven security obligation")

    def test_head_blob_and_transaction_drift(self):
        for p in (replace(self.evidence(), pr_head="0"*40),
                  replace(self.evidence(), blob_sha="0"*40)):
            self.assertEqual(assess_proof(p, Transaction())["decision"], "DENY")
        self.assertEqual(assess_proof(self.evidence(),
                         replace(Transaction(), branch="main"))["decision"], "DENY")
        self.assertEqual(assess_proof(None, Transaction())["decision"], "DENY")

    def test_transport_never_uses_hostile_client(self):
        class Hostile:
            def __getattribute__(self, key):
                raise AssertionError("transport accessed external client")
        r = transport_request(Hostile(), enabled=True, approve=True,
                              token="fake", method="PATCH")
        self.assertEqual(r["operations"], [])
        self.assertFalse(r["write_allowed"])
        self.assertFalse(r["retry_allowed"])


if __name__ == "__main__":
    unittest.main()
