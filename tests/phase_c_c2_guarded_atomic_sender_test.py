import unittest
from scripts.phase_c_c2_guarded_atomic_sender import (
    _post_once_injected, submit_atomic, inspect_atomic_envelope,
)
from scripts.phase_c_atomic_refs_plan import MUTATION, ZERO_SHA
from scripts.phase_c_c2_inert_graphql_envelope import HEAD, NONCE, BRANCH


def packets():
    def wrap(kind, update):
        return {"query": MUTATION, "variables": {"input": {
            "repositoryId": "R_kgDOVNLxvg",
            "clientMutationId": "phase-c-" + kind + "-427-123-" + HEAD[:12],
            "refUpdates": [update]}}}
    return (wrap("reserve", {"name": NONCE, "beforeOid": ZERO_SHA,
                              "afterOid": HEAD, "force": False}),
            wrap("advance", {"name": BRANCH, "beforeOid": HEAD,
                              "afterOid": "b"*40, "force": False}))


class GuardedTests(unittest.TestCase):
    def test_injected_real_transport_cannot_be_called(self):
        def hostile(*args, **kwargs):
            raise AssertionError("Attempted network request")
        a, b = packets()
        for result in (
            _post_once_injected(a, b, "ghs_fake", opener=hostile),
            submit_atomic(a, b, token="ghs_fake", opener=hostile),
            _post_once_injected(opener=hostile),
        ):
            self.assertEqual(result["decision"], "DENY")
            self.assertIs(result["write_allowed"], False)
            self.assertIs(result["network_allowed"], False)
            self.assertIs(result["retry_allowed"], False)

    def test_offline_review_only(self):
        a, b = packets()
        result = inspect_atomic_envelope(a, b)
        self.assertEqual(result["decision"], "REVIEW_ONLY")
        self.assertFalse(result["network_allowed"])
        self.assertFalse(result["write_allowed"])
        self.assertFalse(result["activation_allowed"])

    def test_scope_mismatch_denied(self):
        a, b = packets()
        b["variables"]["input"]["refUpdates"][0]["name"] = "refs/heads/main"
        self.assertEqual(inspect_atomic_envelope(a, b)["decision"], "DENY")

    def test_no_sensitive_transport_imports(self):
        import inspect
        from scripts import phase_c_c2_guarded_atomic_sender as module
        source = inspect.getsource(module)
        self.assertNotIn("urllib", source)
        self.assertNotIn("http.client", source)
        self.assertNotIn("requests.post", source)


if __name__ == "__main__":
    unittest.main()
