"""Offline adversarial boundary checks for C2 readback and inert executor."""
import copy
import unittest
from scripts.phase_c_c2_postwrite_readback import verify_refs, authorize_or_write
from scripts.phase_c_c2_trusted_executor import execute_one_shot
from tests.phase_c_c2_offline_integration_test import readback


class AdversarialReadbackTests(unittest.TestCase):
    def assert_denied(self, result):
        self.assertEqual(result["decision"], "DENY")
        self.assertIs(result["write_allowed"], False)
        self.assertIs(result["retry_allowed"], False)

    def test_missing_and_malformed_independent_get_payloads(self):
        base = readback()
        malformed = [None, False, [], "status 200", {}, {"ref": "x"},
                     {"ref": "x", "object": None}, {"ref": "x", "object": []}]
        for index in (0, 1):
            for value in malformed:
                with self.subTest(index=index, value=repr(value)):
                    pair = list(copy.deepcopy(base))
                    pair[index] = value
                    self.assert_denied(verify_refs(*pair))

    def test_extra_metadata_and_ambiguous_object_type(self):
        for index in (0, 1):
            for change in ("extra-top", "extra-object", "tagged", "wrong-sha"):
                with self.subTest(index=index, change=change):
                    pair = list(copy.deepcopy(readback()))
                    item = pair[index]
                    if change == "extra-top":
                        item["status"] = 200
                    elif change == "extra-object":
                        item["object"]["url"] = "https://example.invalid"
                    elif change == "tagged":
                        item["object"]["type"] = "tag"
                    else:
                        item["object"]["sha"] = "0" * 40
                    self.assert_denied(verify_refs(*pair))

    def test_positive_match_still_never_unlocks_network_or_retry(self):
        result = verify_refs(*readback())
        self.assertEqual(result["decision"], "READBACK_MATCH")
        for function in (execute_one_shot, authorize_or_write):
            decision = function(result, token="untrusted", approved=True)
            self.assertEqual(decision["decision"], "DENY")
            self.assertIs(decision["write_allowed"], False)
            self.assertIs(decision["network_allowed"], False)


if __name__ == "__main__":
    unittest.main()
