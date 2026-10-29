"""End-to-end fail-closed Phase C sandbox audit using fake GitHub GETs only."""
import copy
import unittest

from phase_c_readonly_candidate_verifier_test import (
    fixture, HEAD, CANDIDATE, ROOT,
)
from scripts.phase_c_complete_readonly_audit import audit_sandbox_readonly
from scripts.phase_c_github_readonly_client import (
    GitHubReadError, MissingNonceRef,
)

MAIN = "d" * 40
BRANCH = "refs/heads/test/phase-c-sandbox-20261029"
NONCE = "refs/tags/phase-c-once-issue-100"
MAIN_PATH = ROOT + "/git/ref/heads/main"
BRANCH_PATH = ROOT + "/git/ref/heads/test/phase-c-sandbox-20261029"
NONCE_PATH = ROOT + "/git/ref/tags/phase-c-once-issue-100"


def ref(name, sha, kind="commit"):
    return {"ref": name, "object": {"sha": sha, "type": kind}}


class MockGitHubGET:
    def __init__(self):
        self.data, self.auth = fixture()
        self.data[MAIN_PATH] = ref("refs/heads/main", MAIN)
        self.data[BRANCH_PATH] = ref(BRANCH, HEAD)
        self.nonce_response = None
        self.second_main = None
        self.second_nonce = None
        self.second_branch = None
        self.calls = []
        self.count = {MAIN_PATH: 0, BRANCH_PATH: 0, NONCE_PATH: 0}
        self.fail_path = None

    def __call__(self, path):
        self.calls.append(path)
        if path == self.fail_path:
            raise GitHubReadError("Fake API timeout")
        if path in self.count:
            self.count[path] += 1
        if path == MAIN_PATH and self.count[path] == 2 and self.second_main:
            return copy.deepcopy(self.second_main)
        if path == BRANCH_PATH and self.count[path] == 2 and self.second_branch:
            return copy.deepcopy(self.second_branch)
        if path == NONCE_PATH:
            value = self.nonce_response
            if self.count[path] == 2 and self.second_nonce is not None:
                value = self.second_nonce
            if value is None or value == "404":
                raise MissingNonceRef("GitHub GET 404, not permission proof")
            return copy.deepcopy(value)
        if path not in self.data:
            raise GitHubReadError("API path not found in mocked evidence")
        return copy.deepcopy(self.data[path])

    def audit(self, main=MAIN, candidate=CANDIDATE, auth=None):
        return audit_sandbox_readonly(
            self, 901, 900, 101, self.auth if auth is None else auth,
            candidate, main,
        )


class CompleteReadOnlyAuditTests(unittest.TestCase):
    def assertBlocked(self, result):
        for key in ("write_allowed", "retry_allowed", "merge_allowed",
                    "dispatch_allowed", "production_authorized",
                    "ready_for_activation"):
            self.assertIs(result[key], False)
        self.assertNotIn("query", result)
        self.assertNotIn("variables", result)

    def test_matching_readbacks_still_do_not_authorize_writes(self):
        reader = MockGitHubGET()
        result = reader.audit()
        self.assertEqual(result["decision"], "READ_ONLY_REVIEW_REQUIRED")
        self.assertEqual(result["nonce_status"], "404_NOT_PROOF_OF_ABSENCE")
        self.assertEqual(result["candidate_sha"], CANDIDATE)
        self.assertEqual(result["pinned_main_sha"], MAIN)
        self.assertEqual(result["approved_branch_sha"], HEAD)
        self.assertIn("No durable nonce reservation performed", result["blockers"])
        self.assertBlocked(result)
        self.assertEqual(reader.calls[-6:], [
            MAIN_PATH, BRANCH_PATH, NONCE_PATH,
            MAIN_PATH, BRANCH_PATH, NONCE_PATH,
        ])

    def test_pinned_main_and_approved_branch_drift_denied(self):
        for kind in ("main", "branch"):
            with self.subTest(kind=kind):
                reader = MockGitHubGET()
                if kind == "main":
                    reader.data[MAIN_PATH] = ref("refs/heads/main", "e" * 40)
                else:
                    reader.data[BRANCH_PATH] = ref(BRANCH, "e" * 40)
                result = reader.audit()
                self.assertEqual(result["decision"], "DENY")
                self.assertIn("drift", result["reason"])
                self.assertBlocked(result)

    def test_nonce_present_means_spent_even_when_branch_unchanged(self):
        reader = MockGitHubGET()
        reader.nonce_response = ref(NONCE, HEAD)
        result = reader.audit()
        self.assertEqual(result["decision"], "DENY")
        self.assertIn("already exists", result["reason"])
        self.assertBlocked(result)

    def test_owner_or_candidate_refusal_never_probes_nonce(self):
        for kind in ("wrong-owner", "wrong-candidate", "bad-digest"):
            with self.subTest(kind=kind):
                reader = MockGitHubGET()
                auth = reader.auth.copy()
                candidate = CANDIDATE
                if kind == "wrong-owner":
                    reader.data[ROOT + "/issues/comments/101"]["user"]["id"] = 1
                elif kind == "wrong-candidate":
                    candidate = "f" * 40
                else:
                    auth["new_sha256"] = "f" * 64
                result = reader.audit(candidate=candidate, auth=auth)
                self.assertEqual(result["decision"], "DENY")
                self.assertNotIn(NONCE_PATH, reader.calls)
                self.assertBlocked(result)

    def test_readback_race_refused(self):
        for kind in ("main", "branch", "nonce"):
            with self.subTest(kind=kind):
                reader = MockGitHubGET()
                if kind == "main":
                    reader.second_main = ref("refs/heads/main", "e" * 40)
                elif kind == "branch":
                    reader.second_branch = ref(BRANCH, "e" * 40)
                else:
                    reader.second_nonce = ref(NONCE, HEAD)
                result = reader.audit()
                self.assertEqual(result["decision"], "DENY")
                self.assertIn("changed", result["reason"])
                self.assertBlocked(result)

    def test_bad_ref_shape_and_api_failure_refused(self):
        for kind in ("bad-main-name", "wrong-tag-type", "lost-branch-api"):
            with self.subTest(kind=kind):
                reader = MockGitHubGET()
                if kind == "bad-main-name":
                    reader.data[MAIN_PATH] = ref("refs/heads/other", MAIN)
                elif kind == "wrong-tag-type":
                    reader.nonce_response = ref(NONCE, HEAD, "tag")
                else:
                    reader.fail_path = BRANCH_PATH
                result = reader.audit()
                self.assertEqual(result["decision"], "DENY")
                self.assertBlocked(result)

    def test_invalid_main_or_reader_fails_before_network(self):
        reader = MockGitHubGET()
        result = reader.audit(main="not-a-sha")
        self.assertEqual(result["decision"], "DENY")
        self.assertEqual(reader.calls, [])
        self.assertBlocked(result)

        result = audit_sandbox_readonly(
            None, 901, 900, 101, reader.auth, CANDIDATE, MAIN,
        )
        self.assertEqual(result["decision"], "DENY")
        self.assertBlocked(result)


if __name__ == "__main__":
    unittest.main()
