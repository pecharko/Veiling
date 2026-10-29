import unittest
from scripts.phase_c_c2_graphql_response_gate import inspect_response

RID = "phase-c-reserve-427-123-2595a63c6cd5"
AID = "phase-c-advance-427-123-2595a63c6cd5"


class ResponseGateTests(unittest.TestCase):
    def check(self, payload, phase="reserve", identifier=RID):
        result = inspect_response(payload, identifier, phase=phase)
        self.assertFalse(result["write_allowed"])
        self.assertFalse(result["retry_allowed"])
        self.assertFalse(result["next_write_allowed"])
        return result

    def test_ack_never_promotes_next_mutation(self):
        for phase, ident in (("reserve", RID), ("advance", AID)):
            payload = {"data": {"updateRefs": {"clientMutationId": ident}}}
            self.assertEqual(self.check(payload, phase, ident)["decision"], "ACK_ONLY")

    def test_ambiguous_and_graphql_errors_fail_closed(self):
        variants = (
            None, {}, [], {"data": None},
            {"errors": [{"message": "partial"}], "data": {"updateRefs": {"clientMutationId": RID}}},
            {"errors": [], "data": {"updateRefs": {"clientMutationId": RID}}},
            {"data": {"updateRefs": None}},
            {"data": {"updateRefs": {"clientMutationId": AID}}},
            {"data": {"updateRefs": {"clientMutationId": RID, "unexpected": True}}},
            {"data": {"updateRefs": {"clientMutationId": RID}}, "unexpected": "x"},
        )
        for payload in variants:
            with self.subTest(payload=payload):
                self.assertEqual(self.check(payload)["decision"], "MANUAL_REVIEW")

    def test_untrusted_metadata_rejected(self):
        payload = {"data": {"updateRefs": {"clientMutationId": RID}}}
        for identifier in (None, "", "phase-c-reserve-427-0-2595a63c6cd5",
                           "phase-c-reserve-428-123-2595a63c6cd5", AID):
            self.assertEqual(self.check(payload, identifier=identifier)["decision"], "MANUAL_REVIEW")


if __name__ == "__main__":
    unittest.main()
