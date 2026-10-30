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


# Phase C3 integration tests run in the existing Phase C CI advisory job.
import copy
from unittest.mock import patch
from scripts import development_ci_feedback_adapter as feedback

BASE = "b" * 40
REPO = "pecharko/Veiling"
ROOT = "repos/" + REPO


def ci_fixture():
    event = {
        "action": "completed",
        "repository": {"full_name": REPO},
        "workflow_run": {
            "id": 1234, "head_sha": SHA,
            "event": "pull_request", "status": "completed",
            "conclusion": "failure",
        },
    }
    run = {
        "id": 1234, "head_sha": SHA,
        "event": "pull_request", "status": "completed",
        "conclusion": "failure",
        "repository": {"full_name": REPO},
        "head_repository": {"full_name": REPO},
    }
    pulls = [{
        "number": 321, "state": "open",
        "head": {"sha": SHA, "repo": {"full_name": REPO}},
        "base": {"sha": BASE, "repo": {"full_name": REPO}},
    }]
    jobs = {"total_count": 2, "jobs": [
        {"name": "impact", "status": "completed", "conclusion": "success"},
        {"name": "php-lint", "status": "completed", "conclusion": "failure"},
    ]}
    comparison = {
        "status": "ahead", "behind_by": 0, "ahead_by": 1,
        "total_commits": 1,
        "files": [{"filename": "tests/example_test.php", "status": "modified"}],
    }
    return event, {
        ROOT + "/actions/runs/1234": run,
        ROOT + "/commits/" + SHA + "/pulls?per_page=100": pulls,
        ROOT + "/actions/runs/1234/jobs?per_page=100": jobs,
        ROOT + "/compare/" + BASE + "..." + SHA: comparison,
    }


class DevelopmentCIFeedbackIntegrationTests(unittest.TestCase):
    def test_actual_readonly_adapter_reuses_existing_advisor(self):
        event, replies = ci_fixture()
        requests = []
        def fake_get(path):
            requests.append(path)
            return replies[path]
        outcome = feedback.evaluate(event, fake_get)
        self.assertEqual(outcome["status"], "FIX_CANDIDATE")
        self.assertEqual(outcome["failed_jobs"], ["php-lint"])
        self.assertEqual(outcome["pr"], 321)
        self.assertEqual(outcome["run_id"], 1234)
        self.assertEqual(outcome["evidence_scope"], "single_workflow_read_only")
        self.assertFalse(outcome["overall_pr_ci_verified"])
        self.assertFalse(outcome["write_allowed"])
        self.assertFalse(outcome["dispatch_allowed"])
        self.assertEqual(len(requests), 4)
        self.assertTrue(all(p.startswith(ROOT + "/") for p in requests))

    def test_green_run_is_only_single_workflow_advice(self):
        event, replies = ci_fixture()
        event["workflow_run"]["conclusion"] = "success"
        replies[ROOT + "/actions/runs/1234"]["conclusion"] = "success"
        replies[ROOT + "/actions/runs/1234/jobs?per_page=100"]["jobs"][1]["conclusion"] = "success"
        outcome = feedback.evaluate(event, replies.__getitem__)
        self.assertEqual(outcome["status"], "CI_GREEN_ADVISORY")
        self.assertFalse(outcome["overall_pr_ci_verified"])
        self.assertFalse(outcome["deployment_authorized"])

    def test_protected_paths_and_renames_never_create_fix_candidate(self):
        for path in (".github/workflows/deploy.yml", "private/secret.php",
                     "scripts/verified_deploy_controller.py",
                     "catalogus-public/admin/login.php"):
            event, replies = ci_fixture()
            replies[ROOT + "/compare/" + BASE + "..." + SHA]["files"][0]["filename"] = path
            with self.subTest(path=path):
                outcome = feedback.evaluate(event, replies.__getitem__)
                self.assertEqual(outcome["status"], "HUMAN_REVIEW")
                self.assertFalse(outcome["write_allowed"])
        event, replies = ci_fixture()
        replies[ROOT + "/compare/" + BASE + "..." + SHA]["files"][0]["status"] = "renamed"
        self.assertEqual(feedback.evaluate(event, replies.__getitem__)["status"], "HUMAN_REVIEW")

    def test_invalid_workflow_event_never_queries_github(self):
        event, replies = ci_fixture()
        for change in (
            lambda e: e["workflow_run"].update(event="push"),
            lambda e: e["workflow_run"].update(head_sha="../attack"),
            lambda e: e["workflow_run"].update(status="in_progress"),
            lambda e: e["repository"].update(full_name="other/Repo"),
            lambda e: e.update(action="requested"),
            lambda e: e["workflow_run"].update(id=True),
        ):
            candidate = copy.deepcopy(event)
            change(candidate)
            def refuse_get(_):
                self.fail("No network on invalid event")
            self.assertEqual(feedback.evaluate(candidate, refuse_get)["status"], "HUMAN_REVIEW")

    def test_head_drift_missing_pr_or_wrong_repo_fail_closed(self):
        for change in (
            lambda d: d[ROOT + "/actions/runs/1234"].update(head_sha="c" * 40),
            lambda d: d[ROOT + "/commits/" + SHA + "/pulls?per_page=100"].clear(),
            lambda d: d[ROOT + "/commits/" + SHA + "/pulls?per_page=100"][0].update(state="closed"),
            lambda d: d[ROOT + "/commits/" + SHA + "/pulls?per_page=100"][0]["head"]["repo"].update(full_name="attacker/fork"),
            lambda d: d[ROOT + "/commits/" + SHA + "/pulls?per_page=100"].append(
                copy.deepcopy(d[ROOT + "/commits/" + SHA + "/pulls?per_page=100"][0])),
        ):
            event, replies = ci_fixture()
            change(replies)
            self.assertEqual(feedback.evaluate(event, replies.__getitem__)["status"],
                             "HUMAN_REVIEW")

    def test_incomplete_jobs_and_unfinished_jobs_fail_closed(self):
        for change in (
            lambda d: d[ROOT + "/actions/runs/1234/jobs?per_page=100"].update(total_count=101),
            lambda d: d[ROOT + "/actions/runs/1234/jobs?per_page=100"]["jobs"][0].update(status="queued"),
            lambda d: d[ROOT + "/actions/runs/1234/jobs?per_page=100"]["jobs"][0].update(conclusion="neutral"),
            lambda d: d[ROOT + "/actions/runs/1234/jobs?per_page=100"].update(jobs=[]),
        ):
            event, replies = ci_fixture()
            change(replies)
            result = feedback.evaluate(event, replies.__getitem__)
            self.assertEqual(result["status"], "HUMAN_REVIEW")
            self.assertFalse(result["deployment_authorized"])

    def test_compare_drift_and_unbounded_results_fail_closed(self):
        for change in (
            lambda d: d.update(status="diverged"),
            lambda d: d.update(behind_by=1),
            lambda d: d.update(total_commits=251),
            lambda d: d.update(files=[]),
            lambda d: d.update(files=[
                {"filename": "tests/example_test.php", "status": "modified"}
            ] * 101),
        ):
            event, replies = ci_fixture()
            change(replies[ROOT + "/compare/" + BASE + "..." + SHA])
            self.assertEqual(feedback.evaluate(event, replies.__getitem__)["status"],
                             "HUMAN_REVIEW")

    def test_github_transport_must_use_only_GET_and_fixed_repository(self):
        class Done:
            stdout = b'{"ok":true}'
        with patch.object(feedback.subprocess, "run", return_value=Done()) as runner:
            result = feedback.github_get(ROOT + "/actions/runs/1234")
        self.assertEqual(result, {"ok": True})
        args = runner.call_args.args[0]
        self.assertEqual(args[:4], ["gh", "api", "--method", "GET"])
        self.assertEqual(args[-1], ROOT + "/actions/runs/1234")
        with patch.object(feedback.subprocess, "run") as runner:
            with self.assertRaises(ValueError):
                feedback.github_get("repos/attacker/repo/actions/runs/1234")
            with self.assertRaises(ValueError):
                feedback.github_get(ROOT + "/actions/runs/1234\nPOST")
            runner.assert_not_called()



class DevelopmentCIFeedbackSummaryTests(unittest.TestCase):
    def test_failure_summary_reports_exact_jobs_without_authority(self):
        result = {
            "status": "FIX_CANDIDATE",
            "reason": "Review job logs and propose a separate narrow fix",
            "run_id": 1234,
            "pr": 321,
            "head_sha": SHA,
            "failed_jobs": ["php-lint", "test_job"],
        }
        summary = feedback.format_summary(result)
        self.assertIn("- Failed jobs in this workflow:", summary)
        self.assertIn("  - php-lint", summary)
        self.assertIn("  - test_job", summary)
        self.assertIn("- No automatic code changes, merge, retry, workflow dispatch or deployment.", summary)
        self.assertNotIn("write allowed", summary.lower())

    def test_untrusted_job_names_cannot_inject_markdown_or_newlines(self):
        result = {
            "status": "FIX_CANDIDATE",
            "reason": "Review job logs and propose a separate narrow fix",
            "failed_jobs": ["php\\n### fake green", "](javascript:alert(1))", "<script>"],
        }
        text = feedback.format_summary(result)
        self.assertNotIn("\\n### fake green", text)
        self.assertNotIn("javascript:", text)
        self.assertNotIn("<script>", text)
        self.assertIn("- Scope: this workflow only", text)

    def test_no_failures_is_not_falsely_reported_as_green(self):
        review = feedback.format_summary({
            "status": "HUMAN_REVIEW", "reason": "Incomplete evidence",
        })
        self.assertIn("not independently established", review)
        green = feedback.format_summary({
            "status": "CI_GREEN_ADVISORY", "reason": "All reported jobs successful",
            "failed_jobs": [],
        })
        self.assertIn("none reported in this workflow", green)
        self.assertIn("other PR checks require independent review", green)



if __name__ == "__main__":
    unittest.main()
