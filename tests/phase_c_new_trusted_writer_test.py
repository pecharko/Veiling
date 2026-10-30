"""Adversarial, completely offline tests for new isolated phase-C sandbox."""
import copy
import hashlib
import inspect
import unittest

from scripts import phase_c_new_trusted_writer as w


def evidence(issue=480, candidate="a" * 40, worker="b" * 40):
    fixed = w.deterministic_candidate(w.ORIGINAL)
    owner = {"login": "pecharko", "id": w.OWNER["id"]}
    base = {"source": w.REPO, "enforcement": "active",
            "bypass_actors": [], "current_user_can_bypass": "never"}
    snapshot = {
        "repository": w.REPO, "repository_node_id": w.REPO_NODE,
        "pr": {"number": 475, "state": "open", "draft": True,
               "merged": False, "base_ref": "main", "base_sha": w.BASE_SHA,
               "head_ref": w.BRANCH, "head_sha": w.HEAD,
               "head_repo": w.REPO, "base_repo": w.REPO},
        "branch": {"ref": w.BRANCH, "sha": w.HEAD},
        "source_blob": {"sha": w.OLD_BLOB, "data": w.ORIGINAL},
        "candidate": {"sha": candidate, "parents": [w.HEAD],
                      "files": [{"path": w.TARGET, "status": "modified",
                                 "old_blob": w.OLD_BLOB, "new_blob": w.NEW_BLOB,
                                 "additions": 1, "deletions": 1}],
                      "content": fixed},
        "nonce_status": 404,
        "branch_rule": dict(base, id=w.BRANCH_RULESET_ID, target="branch",
                            conditions={"ref_name": {"include": list(w.BRANCH_RULES),
                                                     "exclude": []}},
                            rules=[{"type": "deletion"}, {"type": "non_fast_forward"}]),
        "tag_rule": dict(base, id=w.TAG_RULESET_ID, target="tag",
                         conditions={"ref_name": {"include": [w.TAG_PATTERN], "exclude": []}},
                         rules=[{"type": "update"}, {"type": "deletion"}]),
    }
    original_issue = {
        "number": issue, "state": "open", "user": owner,
        "body": ("PHASE-C-NEW-PLAN pr=475 issue=" + str(issue)
                 + " head=" + w.HEAD + " blob=" + w.OLD_BLOB),
    }
    comment = {
        "id": 500, "user": owner,
        "issue_url": ("https://api.github.com/repos/" + w.REPO
                      + "/issues/" + str(issue)),
        "body": w.owner_approval_phrase(issue, worker, candidate),
    }
    return snapshot, original_issue, comment


def review(snapshot=None, issue_record=None, comment=None, *,
           issue_number=480, candidate="a" * 40, worker="b" * 40):
    data = evidence(issue_number, candidate, worker)
    return w.make_review_packet(
        snapshot if snapshot is not None else data[0],
        issue_record if issue_record is not None else data[1],
        comment if comment is not None else data[2],
        issue_number=issue_number, comment_id=500,
        worker_sha=worker, candidate_oid=candidate)


class NewSandboxTrustedWriterTests(unittest.TestCase):
    def test_exact_fixture_and_crypto_hashes(self):
        fixed = w.deterministic_candidate(w.ORIGINAL)
        self.assertEqual(w.git_blob_id(w.ORIGINAL), w.OLD_BLOB)
        self.assertEqual(w.git_blob_id(fixed), w.NEW_BLOB)
        self.assertEqual(hashlib.sha256(fixed).hexdigest(), w.NEW_SHA256)
        for bad in (b"return flase;", w.ORIGINAL + b" ", fixed,
                    w.ORIGINAL.replace(b"flase", b"flase flase")):
            with self.subTest(bad=bad):
                with self.assertRaises(ValueError):
                    w.deterministic_candidate(bad)

    def test_all_valid_evidence_remains_inert(self):
        p = review()
        self.assertEqual(p["decision"], "REVIEW_ONLY")
        for flag in ("write_allowed", "network_allowed", "retry_allowed",
                     "activation_allowed", "merge_allowed", "production_allowed"):
            self.assertFalse(p[flag])
        self.assertEqual(p["payload"]["variables"]["input"]["refUpdates"], [
            {"name": "refs/tags/phase-c-once-issue-480", "beforeOid": "0"*40,
             "afterOid": w.HEAD, "force": False},
            {"name": w.BRANCH, "beforeOid": w.HEAD,
             "afterOid": "a"*40, "force": False},
        ])

    def test_spent_old_issue_and_missing_new_issue_rejected(self):
        self.assertEqual(review(issue_number=427)["decision"], "DENY")
        self.assertEqual(review(issue_number=475)["decision"], "DENY")
        self.assertEqual(w.make_review_packet(
            {}, {}, {}, issue_number=480, comment_id=500,
            worker_sha="b"*40, candidate_oid="a"*40)["decision"], "DENY")

    def test_pr_head_base_candidate_and_scope_drift_fail_closed(self):
        mutations = [
            ("pr", "draft", False), ("pr", "merged", True),
            ("pr", "state", "closed"), ("pr", "head_sha", "f"*40),
            ("pr", "base_sha", "f"*40), ("pr", "head_repo", "other/fork"),
            ("branch", "sha", "f"*40),
            ("candidate", "sha", "f"*40), ("candidate", "parents", []),
            ("candidate", "files", []), ("candidate", "content", b"unexpected"),
        ]
        for group, field, value in mutations:
            with self.subTest(group=group, field=field):
                snap, issue, comment = evidence()
                snap[group][field] = value
                self.assertEqual(review(snap, issue, comment)["decision"], "DENY")

    def test_exact_one_file_repair_only(self):
        for field, value in (
            ("path", "catalogus-public/index.php"),
            ("new_blob", "0"*40), ("old_blob", "0"*40),
            ("additions", 2), ("deletions", 0),
        ):
            with self.subTest(field=field):
                snap, issue, comment = evidence()
                snap["candidate"]["files"][0][field] = value
                self.assertEqual(review(snap, issue, comment)["decision"], "DENY")

    def test_owner_issue_and_comment_cannot_be_spoofed(self):
        for field, value in (
            ("body", "go"), ("id", 999),
            ("issue_url", "https://api.github.com/repos/other/Veiling/issues/480"),
            ("user", {"login": "pecharko", "id": 7}),
        ):
            with self.subTest(field=field):
                snap, issue, comment = evidence()
                comment[field] = value
                self.assertEqual(review(snap, issue, comment)["decision"], "DENY")
        snap, issue, comment = evidence()
        issue["user"] = {"login": "pecharko", "id": 1}
        self.assertEqual(review(snap, issue, comment)["decision"], "DENY")

    def test_rulesets_bypass_nonce_and_scope_fail_closed(self):
        for group, field, value in (
            ("branch_rule", "enforcement", "disabled"),
            ("branch_rule", "bypass_actors", [{"actor_id": 1}]),
            ("branch_rule", "current_user_can_bypass", "always"),
            ("tag_rule", "rules", [{"type": "update"}]),
        ):
            with self.subTest(group=group, field=field):
                snap, issue, comment = evidence()
                snap[group][field] = value
                self.assertEqual(review(snap, issue, comment)["decision"], "DENY")
        snap, issue, comment = evidence()
        snap["branch_rule"]["conditions"]["ref_name"]["include"].pop()
        self.assertEqual(review(snap, issue, comment)["decision"], "DENY")
        snap, issue, comment = evidence()
        snap["nonce_status"] = 200
        self.assertEqual(review(snap, issue, comment)["decision"], "DENY")

    def test_in_memory_simulation_is_not_live_write(self):
        refs = {w.BRANCH: w.HEAD}
        answer, changed = w.simulate_atomic(review(), refs)
        self.assertEqual(answer["decision"], "SIMULATED_ONLY")
        self.assertFalse(answer["write_allowed"])
        self.assertEqual(refs, {w.BRANCH: w.HEAD})
        self.assertEqual(changed[w.BRANCH], "a"*40)
        self.assertEqual(changed["refs/tags/phase-c-once-issue-480"], w.HEAD)

    def test_duplicate_nonce_and_competing_branch_never_retry(self):
        for refs in (
            {w.BRANCH: w.HEAD, "refs/tags/phase-c-once-issue-480": w.HEAD},
            {w.BRANCH: "f"*40},
        ):
            answer, unchanged = w.simulate_atomic(review(), refs)
            self.assertEqual(answer["decision"], "DENY")
            self.assertFalse(answer["retry_allowed"])
            self.assertEqual(unchanged, refs)

    def test_timeout_and_conflict_are_terminal(self):
        for fault in ("conflict", "timeout"):
            refs = {w.BRANCH: w.HEAD}
            answer, unchanged = w.simulate_atomic(review(), refs, fault=fault)
            self.assertEqual(answer["decision"], "AMBIGUOUS_STOP_NO_RETRY")
            self.assertFalse(answer["retry_allowed"])
            self.assertEqual(unchanged, refs)

    def test_tampering_with_atomic_payload_is_denied(self):
        for alteration in ("force", "main", "third", "nonce", "repo"):
            p = review()
            data = p["payload"]["variables"]["input"]
            if alteration == "force":
                data["refUpdates"][1]["force"] = True
            elif alteration == "main":
                data["refUpdates"][1]["name"] = "refs/heads/main"
            elif alteration == "third":
                data["refUpdates"].append({"name": "refs/heads/main"})
            elif alteration == "nonce":
                data["refUpdates"][0]["beforeOid"] = w.HEAD
            else:
                data["repositoryId"] = "unexpected"
            answer, _ = w.simulate_atomic(p, {w.BRANCH: w.HEAD})
            self.assertEqual(answer["decision"], "DENY")

    def test_malformed_review_packet_cannot_crash_or_mutate(self):
        for payload in (None, 9, {}, {"variables": None},
                        {"variables": {"input": None}},
                        {"variables": {"input": "invalid"}}):
            p = review()
            p["payload"] = payload
            original = {w.BRANCH: w.HEAD}
            answer, unchanged = w.simulate_atomic(p, original)
            self.assertEqual(answer["decision"], "DENY")
            self.assertFalse(answer["retry_allowed"])
            self.assertEqual(unchanged, original)

    def test_no_network_credentials_or_executable_path(self):
        class Hostile:
            def __getattr__(self, name):
                raise AssertionError("Never inspect injected transport")
        result = w.execute_one_shot(token="secret", opener=Hostile(), enabled=True)
        self.assertEqual(result["decision"], "DENY")
        self.assertFalse(result["network_allowed"])
        for forbidden in ("urllib", "requests", "http.client", "subprocess",
                          "os.environ", "urlopen(", "HTTPSConnection("):
            self.assertNotIn(forbidden, inspect.getsource(w))
        self.assertEqual(w.main([]), 2)
        self.assertEqual(w.main(["--execute"]), 2)


if __name__ == "__main__":
    unittest.main()
