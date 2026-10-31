"""Executable offline model of the ordered, single-use two-stage ref contract.

This fake is NOT GitHub, a writer, a retry mechanism or a live atomicity test.
It checks only the construction and required ordering of the inert plan.
"""
import hashlib
import unittest
from scripts.phase_c_atomic_refs_plan import (
    ZERO_SHA, TARGET, propose_atomic_update_refs,
)
from scripts.phase_c_atomic_ref_reconciliation import reconcile_atomic_refs_readonly
from scripts.phase_c_github_readonly_client import MissingNonceRef, ROOT

ORIGINAL = "a" * 40
CANDIDATE = "c" * 40
BEFORE = "<?php function sandbox() { return flase; }"
AFTER = BEFORE.replace("return flase;", "return false;")


def plan(comment=999):
    return propose_atomic_update_refs({
        "repo": "pecharko/Veiling", "repository_node_id": "R_kgDOVNLxvg",
        "owner_verified": True, "pr_open": True, "from_fork": False,
        "pr_number": 901, "issue_id": 900, "comment_id": comment,
        "branch": "test/phase-c-proof", "target_path": TARGET,
        "head_sha": ORIGINAL, "observed_head_sha": ORIGINAL,
        "blob_sha": "b" * 40, "observed_blob_sha": "b" * 40,
        "proposed_commit_sha": CANDIDATE, "proposed_parent_sha": ORIGINAL,
        "proposed_changed_paths": [TARGET], "proposed_commit_verified": True,
        "old_content": BEFORE, "new_content": AFTER,
        "approved_new_sha256": hashlib.sha256(AFTER.encode()).hexdigest(),
    })


class FakeRefs:
    """Single-process compare-and-set simulator; not persistent GitHub state."""
    def __init__(self):
        self.refs = {"refs/heads/test/phase-c-proof": ORIGINAL}
        self.calls = 0

    def mutate_once(self, stage):
        updates = stage["variables"]["input"]["refUpdates"]
        assert len(updates) == 1, "A rejected branch CAS must not undo a tag"
        update = updates[0]
        name, before, after = (
            update["name"], update["beforeOid"], update["afterOid"]
        )
        self.calls += 1
        if update["force"] is not False:
            return False
        actual = self.refs.get(name)
        if before == ZERO_SHA:
            if actual is not None:
                return False
        elif actual != before:
            return False
        self.refs[name] = after
        return True


class TwoStageContractTests(unittest.TestCase):
    def test_one_reservation_then_branch_success(self):
        x = plan()
        s = FakeRefs()
        self.assertTrue(s.mutate_once(x["reservation"]))
        self.assertTrue(s.mutate_once(x["branch_update"]))
        self.assertEqual(s.refs[x["nonce_ref"]], ORIGINAL)
        self.assertEqual(s.refs[x["sandbox_ref"]], CANDIDATE)
        self.assertFalse(s.mutate_once(x["reservation"]))
        self.assertEqual(s.refs[x["nonce_ref"]], ORIGINAL)

    def test_branch_drift_spends_nonce_and_blocks_new_comment(self):
        x = plan()
        s = FakeRefs()
        self.assertTrue(s.mutate_once(x["reservation"]))
        s.refs[x["sandbox_ref"]] = "d" * 40  # Concurrent branch advance.
        self.assertFalse(s.mutate_once(x["branch_update"]))
        self.assertEqual(s.refs[x["nonce_ref"]], ORIGINAL)
        self.assertFalse(s.mutate_once(plan(comment=1000)["reservation"]))
        self.assertEqual(s.refs[x["sandbox_ref"]], "d" * 40)

    def test_ambiguous_reservation_does_not_advance_branch(self):
        x = plan()
        s = FakeRefs()
        self.assertTrue(s.mutate_once(x["reservation"]))
        # Simulate loss of response: future trusted executor must STOP,
        # not try again nor call the second stage.
        self.assertEqual(s.calls, 1)
        self.assertEqual(s.refs[x["sandbox_ref"]], ORIGINAL)
        self.assertFalse(s.mutate_once(x["reservation"]))
        self.assertEqual(s.refs[x["nonce_ref"]], ORIGINAL)

    def test_reconciliation_of_spent_nonce_after_failed_branch(self):
        x = plan()
        s = FakeRefs()
        self.assertTrue(s.mutate_once(x["reservation"]))
        # Use a fake read-only source: no network or mutation on readback.
        def fake_get(path):
            if path == ROOT:
                return {
                    "full_name": "pecharko/Veiling",
                    "node_id": "R_kgDOVNLxvg",
                    "default_branch": "main",
                    "archived": False,
                }
            if path.startswith(ROOT + "/git/ref/tags/"):
                name = "refs/tags/" + path.split("/git/ref/tags/")[1]
            elif path.startswith(ROOT + "/git/ref/heads/"):
                name = "refs/heads/" + path.split("/git/ref/heads/")[1]
            else:
                raise ValueError("Unexpected endpoint")
            if name not in s.refs:
                raise MissingNonceRef("nonce reported absent")
            return {
                "ref": name, "object": {
                    "type": "commit", "sha": s.refs[name],
                },
            }
        outcome = reconcile_atomic_refs_readonly(
            fake_get, 900, "test/phase-c-proof", ORIGINAL, CANDIDATE,
        )
        self.assertEqual(outcome["decision"], "SPENT_NONCE_BRANCH_UNCHANGED")
        self.assertIs(outcome["retry_allowed"], False)
        self.assertIs(outcome["write_allowed"], False)


if __name__ == "__main__":
    unittest.main()
