import json
import unittest
from scripts.phase_c_c2_graphql_readonly_transport import fetch_repository, submit_mutation, READ_QUERY


class Response:
    def __init__(self, payload):
        self.payload = payload
    def __enter__(self):
        return self
    def __exit__(self, *args):
        pass
    def read(self, limit):
        return json.dumps(self.payload).encode()


class Tests(unittest.TestCase):
    def test_disabled_never_calls_network(self):
        def fail(*args, **kwargs):
            raise AssertionError("Must not send request")
        self.assertEqual(fetch_repository("token", opener=fail)["decision"], "DENY")
        self.assertEqual(submit_mutation("anything")["decision"], "DENY")

    def test_authenticated_get_only_query_over_post(self):
        def fake(request, timeout):
            self.assertEqual(request.get_method(), "POST")
            self.assertEqual(json.loads(request.data)["query"], READ_QUERY)
            self.assertNotIn("mutation", json.loads(request.data)["query"].lower())
            self.assertEqual(timeout, 10)
            return Response({"data":{"repository":{"id":"R_kgDOVNLxvg",
                                                       "nameWithOwner":"pecharko/Veiling"}}})
        result = fetch_repository("ephemeral", enabled=True, opener=fake)
        self.assertEqual(result["decision"], "READ_ONLY_VERIFIED")
        self.assertFalse(result["write_allowed"])

    def test_errors_and_wrong_repo_denied_without_token_echo(self):
        for payload in ({"errors":[]}, {"data":{"repository":None}},
                        {"data":{"repository":{"id":"wrong","nameWithOwner":"pecharko/Veiling"}}}):
            result = fetch_repository("secret-test-value", enabled=True,
                                      opener=lambda *args, **kwargs: Response(payload))
            self.assertEqual(result["decision"], "DENY")
            self.assertNotIn("secret-test-value", str(result))


if __name__ == "__main__":
    unittest.main()
