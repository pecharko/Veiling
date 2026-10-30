"""No-network negative tests for the Phase C2 App-installation evidence gate."""
import copy
import unittest
from scripts.phase_c_c2_installation_proof import review_installation, execute


def fixture():
    return ({"slug": "41lokeren-phase-c-writer"},
            {"id": 5526516, "app_slug": "41lokeren-phase-c-writer",
             "repository_selection": "selected",
             "permissions": {"contents": "write", "metadata": "read"}},
            {"repositories": [{"full_name": "pecharko/Veiling"}]})


class InstallationProofTests(unittest.TestCase):
    def test_valid_offline_evidence_never_enables_writer(self):
        result = review_installation(*fixture())
        self.assertEqual(result["decision"], "REVIEW_ONLY")
        for key in ("write_allowed", "network_allowed", "activation_allowed", "production_allowed"):
            self.assertIs(result[key], False)
        self.assertEqual(execute(result, token="fake")["decision"], "DENY")

    def test_identity_scope_and_permissions_fail_closed(self):
        mutations = [
            (0, "slug", "other-app"),
            (1, "id", 172331568),
            (1, "id", "5526516"),
            (1, "app_slug", "other-app"),
            (1, "repository_selection", "all"),
            (1, "permissions", {"contents": "write", "metadata": "read", "issues": "write"}),
            (1, "permissions", {"contents": "read", "metadata": "read"}),
            (2, "repositories", []),
            (2, "repositories", [{"full_name": "pecharko/Veiling"}, {"full_name": "other/repo"}]),
            (2, "repositories", [{"full_name": "other/repo"}]),
        ]
        for index, key, value in mutations:
            with self.subTest(index=index, key=key, value=value):
                args = list(copy.deepcopy(fixture()))
                args[index][key] = value
                result = review_installation(*args)
                self.assertEqual(result["decision"], "DENY")
                self.assertIs(result["write_allowed"], False)

    def test_untrusted_or_incomplete_payloads_fail_closed(self):
        for index in (0, 1, 2):
            for value in (None, [], "", {}, False):
                with self.subTest(index=index, value=repr(value)):
                    args = list(fixture())
                    args[index] = value
                    result = review_installation(*args)
                    self.assertEqual(result["decision"], "DENY")


if __name__ == "__main__":
    unittest.main()
