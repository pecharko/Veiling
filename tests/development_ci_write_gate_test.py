import unittest
from scripts.development_ci_write_gate import assess_repair_write, prepare_repair_review, _git_blob_sha
import copy


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



if __name__ == "__main__":
    unittest.main()
