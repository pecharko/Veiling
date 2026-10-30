import unittest
from scripts.development_ci_write_gate import assess_repair_write, prepare_repair_review, review_github_readbacks, _git_blob_sha
import copy
import base64


def request():
    return {
        "pr": 42, "expected_head_sha": "a" * 40,
        "actual_head_sha": "a" * 40, "branch": "test/phase-c-sandbox",
        "path": "tests/sandbox_test.php", "base_blob_sha": "b" * 40,
        "replacement": "<?php\nreturn false;\n", "attempts": 0,
        "author_approved": True,
    }


class OfflineWriteGateTest(unittest.TestCase):
    def test_all_valid_is_still_not_a_write_authorization(self):
        result = assess_repair_write(request())
        self.assertEqual(result["decision"], "ELIGIBLE_FOR_SEPARATE_WRITE_APPROVAL")
        self.assertFalse(result["can_write"])
        self.assertFalse(result["can_merge"])
        self.assertFalse(result["can_dispatch"])
        self.assertFalse(result["production_authorized"])

    def test_reject_all_sensitive_paths(self):
        for path in (
            ".github/workflows/verified-deploy-controller.yml",
            "private/visitor_stats.php", "database/migrations/x.sql",
            "scripts/development_ci_write_gate.py",
            "catalogus-public/admin/statistieken.php", "releases/a.json",
            "release-requests/a.json", "tests/../sandbox_test.php",
        ):
            with self.subTest(path=path):
                data = request()
                data["path"] = path
                self.assertEqual(assess_repair_write(data)["decision"], "DENY")

    def test_head_drift_and_other_gates(self):
        for key, value in (
            ("actual_head_sha", "b" * 40), ("branch", "main"),
            ("branch", "feature/geo-test"), ("base_blob_sha", ""),
            ("attempts", 1), ("attempts", False),
            ("author_approved", False), ("replacement", "not-php"),
            ("replacement", "<?php" + "x" * 3000),
            ("pr", True),
        ):
            with self.subTest(key=key):
                data = request()
                data[key] = value
                self.assertEqual(assess_repair_write(data)["decision"], "DENY")


def valid_review_input():
    source = "<?php\nfunction sandbox(): bool {\n    return flase;\n}\n"
    proposed = request()
    proposed["base_blob_sha"] = _git_blob_sha(source)
    proposed["replacement"] = source.replace("return flase;", "return false;")
    return proposed, source


class OfflineRepairReviewTest(unittest.TestCase):
    def test_exact_repair_generates_immutable_sha_bound_packet_no_write(self):
        proposed, source = valid_review_input()
        result = prepare_repair_review(proposed, source)
        self.assertEqual(result["decision"], "PROPOSED_ONLY")
        self.assertEqual(result["original_blob_sha"], _git_blob_sha(source))
        self.assertEqual(result["candidate_blob_sha"],
                         _git_blob_sha(proposed["replacement"]))
        self.assertEqual(len(result["candidate_sha256"]), 64)
        self.assertEqual(len(result["review_sha256"]), 64)
        self.assertEqual(result["head_sha"], "a" * 40)
        for field in ("can_write", "can_merge", "can_dispatch",
                      "production_authorized", "owner_identity_verified",
                      "nonce_reserved", "source_provenance_authenticated"):
            self.assertFalse(result[field], field)
        self.assertEqual(result, prepare_repair_review(proposed, source))

    def test_content_or_blob_tampering_denied(self):
        for mutate in (
            lambda p, s: (p, s.replace("flase", "true")),
            lambda p, s: (p, s + "\n// unexpectedly appended"),
            lambda p, s: ({**p, "base_blob_sha": "0" * 40}, s),
            lambda p, s: ({**p, "replacement": "<?php\nreturn false;\n"}, s),
            lambda p, s: ({**p, "replacement": s}, s),
            lambda p, s: (p, s.replace("return flase;", "return flase;\nreturn flase;")),
            lambda p, s: (p, s.replace("<?php", "<?html")),
            lambda p, s: (p, s + "\0"),
        ):
            with self.subTest(case=repr(mutate)):
                proposal, original = valid_review_input()
                mutated, content = mutate(copy.deepcopy(proposal), original)
                self.assertEqual(prepare_repair_review(mutated, content)["decision"],
                                 "DENY")

    def test_scope_and_approval_mismatch_denied(self):
        for key, bad in (
            ("branch", "main"),
            ("path", "scripts/verified_deploy_controller.py"),
            ("path", "tests/subdir/fixture_test.php"),
            ("attempts", 1),
            ("author_approved", False),
            ("expected_head_sha", "wrong"),
            ("actual_head_sha", "b" * 40),
            ("pr", 0),
        ):
            with self.subTest(key=key):
                proposal, original = valid_review_input()
                proposal[key] = bad
                self.assertEqual(prepare_repair_review(proposal, original)["decision"],
                                 "DENY")

    def test_review_digest_changes_on_pr_or_immutable_head(self):
        proposal, original = valid_review_input()
        original_record = prepare_repair_review(proposal, original)
        for key, other in (("pr", 99), ("expected_head_sha", "c" * 40)):
            modified = copy.deepcopy(proposal)
            modified[key] = other
            if key == "expected_head_sha":
                modified["actual_head_sha"] = other
            record = prepare_repair_review(modified, original)
            self.assertEqual(record["decision"], "PROPOSED_ONLY")
            self.assertNotEqual(original_record["review_sha256"],
                                record["review_sha256"])

    def test_malformed_source_never_generates_a_repair(self):
        proposal, original = valid_review_input()
        for content in (None, b"<?php\nreturn flase;", "", "a" * 2100,
                        "<?php\0return flase;", "<?php\nreturn false;"):
            with self.subTest(content=repr(content)[:30]):
                self.assertEqual(prepare_repair_review(proposal, content)["decision"],
                                 "DENY")



def source_readbacks():
    proposed, original = valid_review_input()
    head = proposed["expected_head_sha"]
    repo = {"full_name": "pecharko/Veiling"}
    pr = {
        "number": proposed["pr"], "state": "open", "draft": True,
        "merged_at": None,
        "head": {"sha": head, "ref": proposed["branch"], "repo": repo},
        "base": {"sha": "c" * 40, "repo": repo},
    }
    branch = {
        "ref": "refs/heads/" + proposed["branch"],
        "object": {"sha": head, "type": "commit"},
    }
    blob = {
        "type": "file", "path": proposed["path"],
        "sha": proposed["base_blob_sha"], "encoding": "base64",
        "size": len(original.encode("utf-8")),
        "content": base64.b64encode(original.encode("utf-8")).decode("ascii"),
    }
    return proposed, pr, branch, blob


class PhaseC5OfflineReadbackTests(unittest.TestCase):
    def test_bound_gitHub_shaped_reads_remain_proposed_only(self):
        proposed, pr, branch, blob = source_readbacks()
        result = review_github_readbacks(proposed, pr, branch, blob)
        self.assertEqual(result["decision"], "PROPOSED_ONLY")
        self.assertTrue(result["readback_structurally_consistent"])
        self.assertEqual(result["readback_evidence_scope"], "supplied_rest_payloads_only")
        self.assertEqual(result["original_blob_sha"], proposed["base_blob_sha"])
        for field in ("can_write", "can_merge", "can_dispatch",
                      "production_authorized", "nonce_reserved",
                      "owner_identity_verified", "source_provenance_authenticated"):
            self.assertFalse(result[field], field)

    def test_pr_identity_draft_status_repo_and_sha_drift_denied(self):
        actions = (
            lambda p, h, b: p.update(number=999),
            lambda p, h, b: p.update(state="closed"),
            lambda p, h, b: p.update(draft=False),
            lambda p, h, b: p.update(merged_at="2026-10-30T00:00:00Z"),
            lambda p, h, b: p["head"].update(sha="d" * 40),
            lambda p, h, b: p["head"].update(ref="main"),
            lambda p, h, b: p["head"]["repo"].update(full_name="attacker/fork"),
            lambda p, h, b: p["base"]["repo"].update(full_name="attacker/fork"),
            lambda p, h, b: p["base"].update(sha="a" * 40),
            lambda p, h, b: p["base"].update(sha="bad"),
        )
        for action in actions:
            proposed, pr, branch, blob = source_readbacks()
            action(pr, branch, blob)
            with self.subTest(action=repr(action)):
                self.assertEqual(review_github_readbacks(
                    proposed, pr, branch, blob)["decision"], "DENY")

    def test_ref_and_content_drift_or_unsupported_encoding_denied(self):
        actions = (
            lambda h, b: h.update(ref="refs/heads/main"),
            lambda h, b: h["object"].update(sha="d" * 40),
            lambda h, b: h["object"].update(type="tag"),
            lambda h, b: b.update(sha="0" * 40),
            lambda h, b: b.update(path="private/secret.php"),
            lambda h, b: b.update(type="dir"),
            lambda h, b: b.update(encoding="none"),
            lambda h, b: b.update(content="**not base64**"),
            lambda h, b: b.update(content=base64.b64encode(
                b"<?php\nreturn flase;\n// inserted").decode("ascii")),
            lambda h, b: b.update(size=2049),
            lambda h, b: b.update(size=True),
        )
        for action in actions:
            proposed, pr, branch, blob = source_readbacks()
            action(branch, blob)
            with self.subTest(action=repr(action)):
                result = review_github_readbacks(proposed, pr, branch, blob)
                self.assertEqual(result["decision"], "DENY")
                self.assertFalse(result["can_write"])

    def test_base64_newline_wrapping_accepted_but_not_whitespace_injection(self):
        proposed, pr, branch, blob = source_readbacks()
        raw = blob["content"]
        blob["content"] = raw[:24] + "\n" + raw[24:]
        self.assertEqual(review_github_readbacks(
            proposed, pr, branch, blob)["decision"], "PROPOSED_ONLY")
        blob["content"] = raw[:24] + " " + raw[24:]
        self.assertEqual(review_github_readbacks(
            proposed, pr, branch, blob)["decision"], "DENY")

    def test_malformed_or_unapproved_packet_cannot_grant_authority(self):
        proposed, pr, branch, blob = source_readbacks()
        for broken in (None, [], {}, {"number": proposed["pr"]}):
            self.assertEqual(review_github_readbacks(
                proposed, broken, branch, blob)["decision"], "DENY")
        proposed["author_approved"] = False
        self.assertEqual(review_github_readbacks(
            proposed, pr, branch, blob)["decision"], "DENY")



if __name__ == "__main__":
    unittest.main()
