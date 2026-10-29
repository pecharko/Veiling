import copy
import unittest
from scripts.phase_c_atomic_refs_plan import MUTATION, ZERO_SHA
from scripts.phase_c_c2_inert_graphql_envelope import (
    HEAD, NONCE, BRANCH, REPOSITORY_NODE, inspect_two_stage_envelopes, submit,
)


def samples():
    first = {"query": MUTATION, "variables": {"input": {
        "repositoryId": REPOSITORY_NODE, "clientMutationId": "phase-c-reserve-427-100-2595",
        "refUpdates": [{"name": NONCE, "beforeOid": ZERO_SHA, "afterOid": HEAD, "force": False}]}}}
    second = {"query": MUTATION, "variables": {"input": {
        "repositoryId": REPOSITORY_NODE, "clientMutationId": "phase-c-advance-427-100-2595",
        "refUpdates": [{"name": BRANCH, "beforeOid": HEAD, "afterOid": "b" * 40, "force": False}]}}}
    return first, second


class InertGraphQLEnvelopeTests(unittest.TestCase):
    def test_valid_shape_remains_inert(self):
        a,b = samples()
        result = inspect_two_stage_envelopes(a,b)
        self.assertEqual(result["decision"], "INERT_ENVELOPES_VALIDATED")
        self.assertFalse(result["network_allowed"])
        self.assertFalse(result["write_allowed"])
        self.assertEqual(submit(a,b)["operations"], [])

    def test_mutated_fields_deny(self):
        paths = (
            (0, "beforeOid", "1"*40), (0, "afterOid", "1"*40),
            (0, "name", BRANCH), (0, "force", True),
            (1, "beforeOid", ZERO_SHA), (1, "name", NONCE),
            (1, "force", True), (1, "afterOid", HEAD),
        )
        for index, field, value in paths:
            a,b=samples()
            target=(a,b)[index]["variables"]["input"]["refUpdates"][0]
            target[field]=value
            self.assertEqual(inspect_two_stage_envelopes(a,b)["decision"], "DENY")

    def test_bulk_or_reversed_mutation_is_denied(self):
        a,b=samples()
        a["variables"]["input"]["refUpdates"].append(b["variables"]["input"]["refUpdates"][0])
        self.assertEqual(inspect_two_stage_envelopes(a,b)["decision"], "DENY")
        a,b=samples()
        self.assertEqual(inspect_two_stage_envelopes(b,a)["decision"], "DENY")

    def test_structurally_untrusted_inputs_denied(self):
        for input in (None, [], {}, {"query": MUTATION}):
            a,b=samples()
            self.assertEqual(inspect_two_stage_envelopes(input,b)["decision"], "DENY")
            self.assertEqual(inspect_two_stage_envelopes(a,input)["decision"], "DENY")
        a,b=samples()
        b["variables"]["input"]["extra"]="evil"
        self.assertEqual(inspect_two_stage_envelopes(a,b)["decision"], "DENY")


if __name__ == "__main__":
    unittest.main()
