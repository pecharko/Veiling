import json
import unittest
from scripts.phase_c_c2_integrated_boundary import inspect_with_app, execute_one_shot
from tests.phase_c_combined_preflight_test import fixture


class Response:
    def __enter__(self): return self
    def __exit__(self, *args): pass
    def read(self, _limit):
        return json.dumps({"data":{"repository":{
            "id":"R_kgDOVNLxvg","nameWithOwner":"pecharko/Veiling"}}}).encode()


class IntegratedTests(unittest.TestCase):
    def test_disabled_never_reaches_network(self):
        e,a,b=fixture()
        def fail(*args, **kwargs): raise AssertionError("Network used")
        self.assertEqual(inspect_with_app("secret",e,a,b,opener=fail)["decision"],"DENY")

    def test_authenticated_read_never_enables_writer(self):
        e,a,b=fixture()
        report=inspect_with_app("ephemeral",e,a,b,enabled=True,opener=lambda *_args,**_kw: Response())
        self.assertEqual(report["decision"],"READ_ONLY_REVIEW")
        for key in ("write_allowed","retry_allowed","mutation_allowed","production_authorized"):
            self.assertIs(report[key],False)
        self.assertNotIn("ephemeral",str(report))

    def test_bad_candidate_even_with_valid_read_denied(self):
        e,a,b=fixture()
        e["candidate_paths"]=["index.php"]
        self.assertEqual(inspect_with_app("ephemeral",e,a,b,enabled=True,
                         opener=lambda *_args,**_kw:Response())["decision"],"DENY")

    def test_write_entrypoint_impossible_with_injected_transport(self):
        class Hostile:
            def __getattr__(self, name):
                raise AssertionError("Untrusted transport used")
        self.assertEqual(execute_one_shot(Hostile(),token="secret")["operations"],[])


if __name__=="__main__": unittest.main()
