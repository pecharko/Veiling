import unittest
from scripts.phase_c_c2_trusted_executor import review_transaction, execute_one_shot
from scripts.phase_c_c2_owner_gate import review_owner_scope
from tests.phase_c_combined_preflight_test import fixture


class TrustedExecutorTests(unittest.TestCase):
    def test_execution_always_denied_with_hostile_transport(self):
        class Hostile:
            def __getattr__(self, name):
                raise AssertionError("Unexpected transport access")
        for answer in (
            execute_one_shot(token="secret", opener=Hostile()),
            execute_one_shot(True, {"approved": True}),
        ):
            self.assertEqual(answer["decision"], "DENY")
            self.assertFalse(answer["network_allowed"])
            self.assertFalse(answer["write_allowed"])
            self.assertEqual(answer["operations"], [])

    def test_owner_metadata_is_never_execution_permission(self):
        from scripts.phase_c_c2_owner_gate import HEAD, BRANCH, NONCE, TARGET
        record = dict(repository="pecharko/Veiling", pr=426, expected_head=HEAD,
                      target_ref=BRANCH, nonce_ref=NONCE,
                      candidate_oid="a"*40, candidate_parent=HEAD,
                      candidate_paths=[TARGET], issue=427,
                      approval_id="427", owner="pecharko",
                      authorized_operation="atomic-nonce-and-sandbox-cas")
        self.assertEqual(review_owner_scope(record)["decision"], "REVIEW_ONLY")
        self.assertEqual(review_transaction(record, {}, {}, {})["decision"], "DENY")


from tests.phase_c_new_trusted_writer_test import NewSandboxTrustedWriterTests  # offline CI discovery bridge
from tests.phase_c_new_offline_receipt_test import OfflineReceiptTests  # offline-only regression
from tests.phase_c_new_local_one_shot_worker_test import PhaseCNewWorkerTests  # offline mock-only

from scripts import phase_c_new_local_one_shot_worker as new_worker
from unittest.mock import patch as new_patch


class PhaseCNewIssueBoundWireContractTests(unittest.TestCase):
    def test_new_issue_and_owner_comment_bind_the_exact_graphql_packet(self):
        with new_patch.object(new_worker, "ISSUE", 9013), new_patch.object(
            new_worker, "NONCE", "refs/tags/phase-c-once-issue-9013"
        ):
            packet = new_worker.mutation(600)
            self.assertEqual(
                packet["variables"]["input"]["clientMutationId"],
                "phase-c-new-9013-600-" + new_worker.HEAD[:12],
            )
            self.assertEqual(
                [ref["name"] for ref in packet["variables"]["input"]["refUpdates"]],
                ["refs/tags/phase-c-once-issue-9013", new_worker.BRANCH],
            )



if __name__ == "__main__":
    unittest.main()
