"""Offline negative tests for phase-C2 single-call transport boundary."""
import copy
import unittest
from scripts.phase_c_atomic_refs_plan import MUTATION, ZERO_SHA
from scripts.phase_c_c2_inert_graphql_envelope import NONCE, BRANCH, HEAD, REPOSITORY_NODE
from scripts.phase_c_c2_transport_boundary import CANDIDATE, inspect_transport_payload, send


def sample():
    return {"query": MUTATION, "variables": {"input": {
        "repositoryId": REPOSITORY_NODE, "clientMutationId": "phase-c-atomic-427-6493461101-" + HEAD[:12],
        "refUpdates": [
            {"name": NONCE, "beforeOid": ZERO_SHA, "afterOid": HEAD, "force": False},
            {"name": BRANCH, "beforeOid": HEAD, "afterOid": CANDIDATE, "force": False},
        ]}}}


class TransportBoundaryTest(unittest.TestCase):
    def test_exact_input_stays_review_only(self):
        x = inspect_transport_payload(sample())
        self.assertEqual(x["decision"], "REVIEW_ONLY")
        for key in ("write_allowed", "network_allowed", "retry_allowed", "production_allowed"):
            self.assertIs(x[key], False)
        self.assertEqual(send(sample(), token="fake", opener=lambda _: None)["decision"], "DENY")

    def test_alterations_deny(self):
        for field, value in (("repositoryId", "wrong"), ("clientMutationId", "phase-c-atomic-428-any"),
                             ("refUpdates", [])):
            with self.subTest(field=field):
                x = sample()
                x["variables"]["input"][field] = value
                self.assertEqual(inspect_transport_payload(x)["decision"], "DENY")
        for index, field, value in ((0, "beforeOid", HEAD), (0, "force", True),
                                    (1, "afterOid", HEAD), (1, "force", True),
                                    (1, "name", "refs/heads/main")):
            with self.subTest(index=index, field=field):
                x = sample()
                x["variables"]["input"]["refUpdates"][index][field] = value
                self.assertEqual(inspect_transport_payload(x)["decision"], "DENY")

    def test_malformed_inputs_deny(self):
        for x in (None, "", [], {}, {"query": MUTATION}):
            self.assertEqual(inspect_transport_payload(x)["decision"], "DENY")
        x = sample()
        x["variables"]["input"]["extra"] = True
        self.assertEqual(inspect_transport_payload(x)["decision"], "DENY")
        x = sample()
        x["variables"]["input"]["refUpdates"].reverse()
        self.assertEqual(inspect_transport_payload(x)["decision"], "DENY")


if __name__ == "__main__":
    unittest.main()
