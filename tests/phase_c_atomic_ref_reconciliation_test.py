"""Offline tests: fail-closed post-updateRefs read-only reconciliation."""
import unittest

from scripts.phase_c_github_readonly_client import (
    ROOT, GitHubReadError, MissingNonceRef,
)
from scripts.phase_c_atomic_ref_reconciliation import reconcile_atomic_refs_readonly

ISSUE_ID = 900
BRANCH = "test/phase-c-sandbox-20261029"
OLD, CANDIDATE = "a" * 40, "b" * 40
TAG_NAME = "refs/tags/phase-c-once-issue-" + str(ISSUE_ID)
HEAD_NAME = "refs/heads/" + BRANCH
TAG_PATH = ROOT + "/git/ref/tags/phase-c-once-issue-" + str(ISSUE_ID)
HEAD_PATH = ROOT + "/git/ref/heads/" + BRANCH
META = {
    "full_name": "pecharko/Veiling",
    "node_id": "R_kgDOVNLxvg",
    "archived": False,
    "default_branch": "main",
}


def ref(name, sha, kind="commit"):
    return {"ref": name, "object": {"sha": sha, "type": kind}}


class RefReader:
    def __init__(self, tag, head, second_tag=None, second_head=None):
        self.tags = [tag, tag if second_tag is None else second_tag]
        self.heads = [head, head if second_head is None else second_head]
        self.calls = []
        self.metadata = META.copy()

    def __call__(self, path):
        self.calls.append(path)
        if path == ROOT:
            return self.metadata
        if path == TAG_PATH:
            value = self.tags.pop(0)
            if value == "404":
                raise MissingNonceRef("nonce tag returned 404")
            if value == "error":
                raise GitHubReadError("rate limit or forbidden")
            return value
        if path == HEAD_PATH:
            value = self.heads.pop(0)
            if value == "error":
                raise GitHubReadError("unverifiable branch")
            return value
        raise AssertionError("Unexpected ref GET")


def reconcile(reader, issue_id=ISSUE_ID, branch=BRANCH, old=OLD,
              candidate=CANDIDATE):
    return reconcile_atomic_refs_readonly(
        reader, issue_id, branch, old, candidate,
    )


class AtomicRefReconciliationTests(unittest.TestCase):
    def assertNoMutatingAuthority(self, result):
        for flag in ("write_allowed", "retry_allowed", "merge_allowed",
                     "dispatch_allowed", "production_authorized"):
            self.assertIs(result[flag], False)

    def test_matching_tag_and_branch_is_readback_only(self):
        reader = RefReader(ref(TAG_NAME, CANDIDATE), ref(HEAD_NAME, CANDIDATE))
        result = reconcile(reader)
        self.assertEqual(result["decision"], "MATCHING_REFS_OBSERVED")
        self.assertNoMutatingAuthority(result)
        self.assertEqual(reader.calls, [ROOT, TAG_PATH, HEAD_PATH,
                                        TAG_PATH, HEAD_PATH])

    def test_404_and_unchanged_branch_does_not_enable_retry(self):
        reader = RefReader("404", ref(HEAD_NAME, OLD))
        result = reconcile(reader)
        self.assertEqual(result["decision"], "UNCHANGED_REPORTED_NO_RETRY")
        self.assertNoMutatingAuthority(result)

    def test_split_brain_denied(self):
        combinations = (
            (ref(TAG_NAME, CANDIDATE), ref(HEAD_NAME, OLD)),
            ("404", ref(HEAD_NAME, CANDIDATE)),
            (ref(TAG_NAME, "f" * 40), ref(HEAD_NAME, CANDIDATE)),
            (ref(TAG_NAME, CANDIDATE), ref(HEAD_NAME, "f" * 40)),
        )
        for tag, head in combinations:
            with self.subTest(tag=tag, head=head):
                reader = RefReader(tag, head)
                result = reconcile(reader)
                self.assertEqual(result["decision"], "MANUAL_REVIEW")
                self.assertNoMutatingAuthority(result)

    def test_change_between_readbacks_denied(self):
        reader = RefReader(
            "404", ref(HEAD_NAME, OLD),
            second_tag=ref(TAG_NAME, CANDIDATE),
            second_head=ref(HEAD_NAME, CANDIDATE),
        )
        result = reconcile(reader)
        self.assertEqual(result["decision"], "MANUAL_REVIEW")
        self.assertIn("changed", result["reason"])
        self.assertNoMutatingAuthority(result)

    def test_unverifiable_refs_fail_closed(self):
        cases = (
            ("error", ref(HEAD_NAME, OLD)),
            ("404", "error"),
            (ref(TAG_NAME, CANDIDATE, "tag"), ref(HEAD_NAME, CANDIDATE)),
            (ref(TAG_NAME, CANDIDATE), ref("refs/heads/main", CANDIDATE)),
            (ref(TAG_NAME, "BAD"), ref(HEAD_NAME, CANDIDATE)),
            ({}, ref(HEAD_NAME, OLD)),
        )
        for tag, head in cases:
            with self.subTest(tag=tag, head=head):
                result = reconcile(RefReader(tag, head))
                self.assertEqual(result["decision"], "MANUAL_REVIEW")
                self.assertNoMutatingAuthority(result)

    def test_wrong_repository_metadata_denied(self):
        for field, value in (
            ("full_name", "attacker/fork"),
            ("node_id", "R_kgDOOther"),
            ("default_branch", "develop"),
            ("archived", True),
        ):
            with self.subTest(field=field):
                reader = RefReader("404", ref(HEAD_NAME, OLD))
                reader.metadata[field] = value
                result = reconcile(reader)
                self.assertEqual(result["decision"], "MANUAL_REVIEW")
                self.assertNoMutatingAuthority(result)

    def test_malformed_scope_never_reaches_client(self):
        cases = (
            (False, BRANCH, OLD, CANDIDATE),
            (0, BRANCH, OLD, CANDIDATE),
            (900, "main", OLD, CANDIDATE),
            (900, "test/phase-c-../main", OLD, CANDIDATE),
            (900, BRANCH, "wrong", CANDIDATE),
            (900, BRANCH, OLD, OLD),
            (900, BRANCH, OLD, "z" * 40),
        )
        for issue, branch, old, candidate in cases:
            with self.subTest(issue=issue, branch=branch):
                reader = RefReader("404", ref(HEAD_NAME, OLD))
                result = reconcile(reader, issue, branch, old, candidate)
                self.assertEqual(result["decision"], "MANUAL_REVIEW")
                self.assertEqual(reader.calls, [])
                self.assertNoMutatingAuthority(result)

    def test_api_outage_denied_without_retry(self):
        def outage(_path):
            raise OSError("API unavailable")
        result = reconcile(outage)
        self.assertEqual(result["decision"], "MANUAL_REVIEW")
        self.assertNoMutatingAuthority(result)


if __name__ == "__main__":
    unittest.main()
