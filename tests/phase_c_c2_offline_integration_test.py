"""Phase C2 end-to-end OFFLINE safety regression: no network or writes."""
import copy
import unittest

from scripts.phase_c_c2_owner_gate import HEAD, BRANCH, NONCE, TARGET
from scripts.phase_c_c2_trusted_executor import review_transaction, execute_one_shot
from scripts.phase_c_c2_postwrite_readback import (
    CANDIDATE, verify_refs, authorize_or_write,
)
from tests.phase_c_combined_preflight_test import fixture


def owner_record(candidate):
    return dict(repository="pecharko/Veiling", pr=426,
                expected_head=HEAD, target_ref=BRANCH, nonce_ref=NONCE,
                candidate_oid=candidate, candidate_parent=HEAD,
                candidate_paths=[TARGET], issue=427, approval_id="427",
                owner="pecharko",
                authorized_operation="atomic-nonce-and-sandbox-cas")


def readback():
    return ({"ref": BRANCH, "object": {"type": "commit", "sha": CANDIDATE}},
            {"ref": NONCE, "object": {"type": "commit", "sha": HEAD}})


class OfflineIntegrationTests(unittest.TestCase):
    def test_inert_preflight_never_grants_execution(self):
        evidence, reserve, advance = fixture()
        review = review_transaction(owner_record(evidence["candidate_oid"]),
                                    evidence, reserve, advance)
        self.assertIn(review["decision"], ("DENY", "READY_FOR_INDEPENDENT_REVIEW"))
        self.assertIs(review["write_allowed"], False)
        self.assertIs(review["network_allowed"], False)
        self.assertEqual(review["operations"], [])
        for output in (execute_one_shot(review, token="untrusted"),
                       authorize_or_write(review)):
            self.assertEqual(output["decision"], "DENY")
            self.assertIs(output["write_allowed"], False)

    def test_positive_readback_is_not_an_authorization(self):
        branch, nonce = readback()
        result = verify_refs(branch, nonce)
        self.assertEqual(result["decision"], "READBACK_MATCH")
        for key in ("write_allowed", "retry_allowed", "mutation_allowed",
                    "production_authorized"):
            self.assertIs(result[key], False)
        self.assertEqual(execute_one_shot(result)["decision"], "DENY")

    def test_readback_drifts_fail_closed_and_do_not_retry(self):
        for index, field, wrong in (
            (0, "sha", HEAD), (1, "sha", CANDIDATE),
            (0, "ref", "refs/heads/main"),
            (1, "ref", "refs/tags/unknown"),
            (0, "type", "tag"), (1, "type", "tag"),
        ):
            with self.subTest(index=index, field=field):
                pair = list(copy.deepcopy(readback()))
                target = pair[index]["object"] if field in ("sha", "type") else pair[index]
                target[field] = wrong
                result = verify_refs(*pair)
                self.assertEqual(result["decision"], "DENY")
                self.assertIs(result["retry_allowed"], False)
                self.assertIs(result["write_allowed"], False)

    def test_owner_and_preflight_drift_cannot_be_promoted(self):
        evidence, reserve, advance = fixture()
        scope = owner_record(evidence["candidate_oid"])
        scope["candidate_oid"] = "c" * 40
        result = review_transaction(scope, evidence, reserve, advance)
        self.assertEqual(result["decision"], "DENY")
        self.assertIs(result["write_allowed"], False)
        evidence, reserve, advance = fixture()
        evidence["nonce_absent_read"] = False
        result = review_transaction(owner_record(evidence["candidate_oid"]),
                                    evidence, reserve, advance)
        self.assertEqual(result["decision"], "DENY")
        self.assertIs(result["network_allowed"], False)


if __name__ == "__main__":
    unittest.main()
