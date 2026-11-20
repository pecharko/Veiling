"""Offline regression gate: no GitHub Actions workflow may merge a PR unattended.

This is defense in depth, not GitHub branch protection or owner authentication.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOWS = ROOT / ".github" / "workflows"
REMOVED_WORKFLOW = WORKFLOWS / "controlled-auto-merge.yml"
MERGE_CALLS = (
    re.compile(r"\b(?:github\.)?rest\.pulls\.merge\s*\(", re.IGNORECASE),
    re.compile(r"\bgh\s+pr\s+merge\b", re.IGNORECASE),
    re.compile(r"\benablePullRequestAutoMerge\b", re.IGNORECASE),
    re.compile(r"\b(?:POST|PUT)\s+[^\n]{0,160}/pulls/[^\n]{1,160}/merge\b", re.IGNORECASE),
)


def forbidden_merge_markers(workflow_source: str) -> list[str]:
    """Detect known unattended merge primitives in workflow YAML/script blocks."""
    return [pattern.pattern for pattern in MERGE_CALLS if pattern.search(workflow_source)]


class NoUnattendedMergeTest(unittest.TestCase):
    def test_old_automerge_workflow_is_gone(self):
        self.assertFalse(REMOVED_WORKFLOW.exists(),
                         "The former CI-triggered auto-merge workflow must stay deleted")

    def test_no_workflow_invokes_merge_api(self):
        self.assertTrue(WORKFLOWS.is_dir(), "GitHub workflows must be present")
        offenders = {}
        for path in sorted(list(WORKFLOWS.glob("*.yml")) + list(WORKFLOWS.glob("*.yaml"))):
            matches = forbidden_merge_markers(path.read_text(encoding="utf-8"))
            if matches:
                offenders[path.name] = matches
        self.assertFalse(offenders, f"Unexpected merge-capable workflow: {offenders}")

    def test_detects_previous_failure(self):
        self.assertTrue(forbidden_merge_markers("await github.rest.pulls.merge({merge_method:'merge'});"))

    def test_detects_gh_cli_merge(self):
        self.assertTrue(forbidden_merge_markers("run: gh pr merge 641 --squash"))

    def test_detects_graphql_auto_merge(self):
        self.assertTrue(forbidden_merge_markers("mutation { enablePullRequestAutoMerge(input: {}) }"))

    def test_readonly_workflow_is_allowed(self):
        self.assertFalse(forbidden_merge_markers("on: workflow_run\npermissions:\n  contents: read\n  actions: read\n"))


if __name__ == "__main__":
    unittest.main()
