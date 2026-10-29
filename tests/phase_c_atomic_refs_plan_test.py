"""Offline adversarial contract for the not-yet-activated atomic ref plan."""
import hashlib
import unittest
from scripts.phase_c_atomic_refs_plan import (
    ZERO_SHA, MUTATION, TARGET, propose_atomic_update_refs,
)

OLD = "<?php function phase_c_sandbox() { return flase; }"
NEW = OLD.replace("return flase;", "return false;", 1)


def evidence():
    return {
        "repo": "pecharko/Veiling",
        "repository_node_id": "R_kgDOVNLxvg",
        "owner_verified": True, "pr_open": True, "from_fork": False,
        "pr_number": 901, "issue_id": 903, "comment_id": 904,
        "branch": "test/phase-c-sandbox-20261029",
        "target_path": TARGET,
        "head_sha": "a" * 40, "observed_head_sha": "a" * 40,
        "blob_sha": "b" * 40, "observed_blob_sha": "b" * 40,
        "proposed_commit_sha": "c" * 40,
        "proposed_parent_sha": "a" * 40,
        "proposed_changed_paths": [TARGET],
        "proposed_commit_verified": True,
        "old_content": OLD, "new_content": NEW,
        "approved_new_sha256": hashlib.sha256(NEW.encode()).hexdigest(),
    }


class AtomicRefsPlanTests(unittest.TestCase):
    def test_valid_plan_is_never_permission(self):
        result = propose_atomic_update_refs(evidence())
        self.assertEqual(result["decision"], "REVIEW_ONLY")
        self.assertIs(result["write_allowed"], False)
        self.assertIs(result["merge_allowed"], False)
        self.assertIs(result["dispatch_allowed"], False)
        self.assertIs(result["production_authorized"], False)
        self.assertEqual(result["query"], MUTATION)
        self.assertIn("updateRefs", result["query"])

        data = result["variables"]["input"]
        self.assertEqual(data["repositoryId"], "R_kgDOVNLxvg")
        self.assertEqual(len(data["refUpdates"]), 2)
        nonce, branch = data["refUpdates"]
        self.assertEqual(nonce, {
            "name": "refs/tags/phase-c-once-issue-903",
            "beforeOid": ZERO_SHA,
            "afterOid": "c" * 40,
            "force": False,
        })
        self.assertEqual(branch, {
            "name": "refs/heads/test/phase-c-sandbox-20261029",
            "beforeOid": "a" * 40,
            "afterOid": "c" * 40,
            "force": False,
        })
        self.assertEqual(result["expected_changed_paths"], [TARGET])

    def test_second_comment_does_not_reset_issue_nonce(self):
        first = evidence()
        second = evidence()
        second["comment_id"] = 905
        a = propose_atomic_update_refs(first)
        b = propose_atomic_update_refs(second)
        self.assertEqual(a["nonce_ref"], b["nonce_ref"])
        self.assertNotEqual(
            a["variables"]["input"]["clientMutationId"],
            b["variables"]["input"]["clientMutationId"],
        )

    def test_untrusted_evidence_denied(self):
        cases = [
            ("repo", "attacker/fork"),
            ("owner_verified", 1),
            ("pr_open", False),
            ("from_fork", True),
            ("pr_number", False),
            ("issue_id", 0),
            ("comment_id", "904"),
            ("repository_node_id", "bad!"),
            ("repository_node_id", "R_kgDOOtherRepoId"),
            ("branch", "main"),
            ("branch", "test/phase-c-../../main"),
            ("target_path", ".github/workflows/php-lint.yml"),
            ("head_sha", "z" * 40),
            ("observed_head_sha", "f" * 40),
            ("blob_sha", None),
            ("observed_blob_sha", "f" * 40),
            ("proposed_commit_sha", "a" * 40),
            ("proposed_parent_sha", "f" * 40),
            ("proposed_changed_paths", [TARGET, ".github/workflows/php-lint.yml"]),
            ("proposed_commit_verified", False),
            ("old_content", OLD + OLD),
            ("old_content", "not php: return flase;"),
            ("new_content", "<?php return true;"),
            ("new_content", NEW + " /* unexpected */"),
            ("approved_new_sha256", "0" * 64),
        ]
        for key, value in cases:
            with self.subTest(key=key, value=str(value)[:30]):
                case = evidence()
                case[key] = value
                verdict = propose_atomic_update_refs(case)
                self.assertEqual(verdict["decision"], "DENY")
                self.assertIs(verdict["write_allowed"], False)
                self.assertNotIn("variables", verdict)

    def test_missing_evidence_and_oversized_content_denied(self):
        self.assertEqual(propose_atomic_update_refs(None)["decision"], "DENY")
        for key in evidence():
            with self.subTest(missing=key):
                case = evidence()
                del case[key]
                self.assertEqual(propose_atomic_update_refs(case)["decision"], "DENY")
        case = evidence()
        case["old_content"] = "<?php" + "a" * 2049 + "return flase;"
        case["new_content"] = case["old_content"].replace("return flase;", "return false;")
        self.assertEqual(propose_atomic_update_refs(case)["decision"], "DENY")


if __name__ == "__main__":
    unittest.main()
