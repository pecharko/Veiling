import io
import json
import unittest
from scripts.phase_c_app_readonly_transport import read_endpoint


class Response:
    def __init__(self, payload):
        self.payload = payload
    def __enter__(self):
        return self
    def __exit__(self, *args):
        return False
    def read(self, n):
        return json.dumps(self.payload).encode()


class TransportTests(unittest.TestCase):
    def test_disabled_never_calls_transport(self):
        def hostile(*args, **kwargs):
            raise AssertionError("Network must remain offline")
        for path in ("/app", "/installation/repositories", "/repos/pecharko/Veiling",
                     "/git/refs/heads/main", "https://evil.invalid", "/app?x=1"):
            r = read_endpoint(path, "fake", opener=hostile)
            self.assertEqual(r["decision"], "DENY")
            self.assertFalse(r["write_allowed"])

    def test_only_get_allowlisted_when_explicitly_enabled(self):
        observed = []
        def fake(req, timeout):
            observed.append((req.get_method(), req.full_url, timeout))
            return Response({"name": "fake"})
        for path in ("/installation/repositories",):
            r = read_endpoint(path, "fake", enabled=True, opener=fake)
            self.assertFalse(r["write_allowed"])
            self.assertEqual(r["read_only_payload"], {"name": "fake"})
        self.assertEqual([x[0] for x in observed], ["GET"])
        self.assertTrue(all(x[1].startswith("https://api.github.com/") for x in observed))

    def test_rejects_scope_elevation_even_when_enabled(self):
        def hostile(*args, **kwargs):
            raise AssertionError("Invalid path touched transport")
        for path in ("/repos/pecharko/Veiling", "/app", "/app/installations/176547610",
                     "/app/../repos", "/app?foo=bar", None):
            self.assertFalse(read_endpoint(path, "fake", enabled=True,
                                           opener=hostile)["write_allowed"])

    def test_token_never_in_error_result(self):
        def failing(*args, **kwargs):
            raise OSError("token-secret")
        result = read_endpoint("/installation/repositories", "token-secret", enabled=True, opener=failing)
        self.assertNotIn("token-secret", str(result))
        self.assertNotIn("read_only_payload", result)


if __name__ == "__main__":
    unittest.main()
