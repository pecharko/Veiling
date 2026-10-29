import unittest
from scripts.development_ci_advisor import assess

SHA = "a" * 40


def sample(paths=None, jobs=None):
    return {"head_sha": SHA, "changed_paths": paths or ["tests/example_test.php"],
            "jobs": jobs or [{"name": "PHP syntax", "conclusion": "failure"}]}


class DevelopmentCIAdvisorTest(unittest.TestCase):
    def test_narrow_failure_is_advisory_only(self):
        r = assess(sample())
        self.assertEqual(r["status"], "FIX_CANDIDATE")
        self.assertFalse(r["automatic_code_changes"])
        self.assertFalse(r["deployment_authorized"])

    def test_protected_paths_fail_closed(self):
        for path in (".github/workflows/release.yml", "private/visitor_stats.php",
                     "database/migrations/new.sql", "scripts/verified_deploy_controller.py",
                     "release-requests/example.json", "../tests/example_test.php"):
            with self.subTest(path=path):
                r = assess(sample(paths=[path]))
                self.assertEqual(r["status"], "HUMAN_REVIEW")
                self.assertFalse(r["deployment_authorized"])

    def test_many_failures_require_review(self):
        jobs = [{"name": str(i), "conclusion": "failure"} for i in range(3)]
        self.assertEqual(assess(sample(jobs=jobs))["status"], "HUMAN_REVIEW")

    def test_green_is_not_authorization(self):
        r = assess(sample(jobs=[{"name": "PHP syntax", "conclusion": "success"}]))
        self.assertEqual(r["status"], "CI_GREEN_ADVISORY")
        self.assertFalse(r["deployment_authorized"])

    def test_bad_input_fails_closed(self):
        with self.assertRaises(ValueError):
            assess({"head_sha": "invalid", "changed_paths": ["tests/example_test.php"],
                    "jobs": [{"name": "ok", "conclusion": "success"}]})


if __name__ == "__main__":
    unittest.main()
