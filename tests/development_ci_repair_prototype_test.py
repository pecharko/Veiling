import unittest
from scripts.development_ci_repair_prototype import propose_fixture_fix


class OfflineRepairPrototypeTest(unittest.TestCase):
    def test_only_specific_fixture_proposes_change(self):
        proposal = propose_fixture_fix(
            "tests/fixtures/phase_c_typo.php",
            "<?php function example() { return flase; }",
            "phase-c-fixture-expected-true")
        self.assertEqual(proposal.status, "PROPOSED_ONLY")
        self.assertEqual(proposal.old, "return flase;")
        self.assertEqual(proposal.new, "return false;")

    def test_no_production_or_unknown_path(self):
        for path in ("private/visitor_stats.php",
                     "catalogus-public/admin/statistieken.php",
                     "tests/visitor_geo_record_test.php",
                     ".github/workflows/shared-ovh-release.yml"):
            with self.subTest(path=path):
                proposal = propose_fixture_fix(path, "return flase;", "phase-c-fixture-expected-true")
                self.assertEqual(proposal.status, "HUMAN_REVIEW")

    def test_no_ambiguous_or_unknown_error(self):
        self.assertEqual(propose_fixture_fix("tests/fixtures/phase_c_typo.php",
            "return flase; return flase;", "phase-c-fixture-expected-true").status, "HUMAN_REVIEW")
        self.assertEqual(propose_fixture_fix("tests/fixtures/phase_c_typo.php",
            "return flase;", "different-error").status, "HUMAN_REVIEW")


if __name__ == "__main__":
    unittest.main()
