"""Adversarial offline-only verification of the D1 candidate proposer."""
import copy
import json
import subprocess
import sys
import unittest
from scripts.development_codefix_candidate import git_blob, propose

SHA = "a" * 40
PHP = "<?php\nfunction x(): bool {\n    return flase;\n}\n"
CSS = "/* catalog */\n.panel {\n    dispaly: flex;\n}\n"


def payload(source=PHP, path="tests/example_test.php"):
    return {
        "repository": "pecharko/Veiling", "issue": 500,
        "head_sha": SHA, "path": path,
        "base_blob_sha": git_blob(source), "source": source,
    }


class PhaseD1ProposalTests(unittest.TestCase):
    def test_php_proposes_only(self):
        r = propose(payload())
        self.assertEqual(r["decision"], "PROPOSED_ONLY")
        self.assertEqual(r["rule"], "php-return-false-typo")
        self.assertIn("+    return false;", r["patch"])
        self.assertEqual(r["candidate_blob_sha"], git_blob(PHP.replace("flase", "false")))
        self.assertEqual(r, propose(payload()))
        for name in ("can_write", "can_merge", "can_dispatch", "can_deploy",
                     "retry_allowed", "author_approval_verified", "github_origin_verified"):
            self.assertFalse(r[name], name)

    def test_real_public_php_is_proposal_only(self):
        r = propose(payload(PHP, "catalogus-public/index.php"))
        self.assertEqual(r["decision"], "PROPOSED_ONLY")
        self.assertFalse(r["can_deploy"])

    def test_css_proposes_only(self):
        r = propose(payload(CSS, "catalogus-public/assets/site.css"))
        self.assertEqual(r["decision"], "PROPOSED_ONLY")
        self.assertEqual(r["rule"], "css-display-typo")
        self.assertIn("+    display: flex;", r["patch"])

    def test_true_and_align_rules(self):
        x = payload(PHP.replace("flase", "ture"))
        self.assertEqual(propose(x)["rule"], "php-return-true-typo")
        css = CSS.replace("dispaly: flex", "algin-items: center")
        self.assertEqual(propose(payload(css, "catalogus-public/assets/styles.css"))["rule"], "css-align-items-typo")

    def test_unsupported_and_protected_paths(self):
        for path in (
                "private/x.php", ".github/workflows/php-lint.yml", "database/x.sql",
                "scripts/phase_c_new_local_one_shot_worker.py", "release-requests/x.json",
                "catalogus-public/admin/login.php", "catalogus-public/assets/admin/settings.css",
                "tests/../index.php", "tests//x_test.php", "/tests/x_test.php",
                "catalogus-public/assets/x.js", "tests/x_test.php/../x_test.php",
                "catalogus-public/pecharko.php/../index.php"):
            with self.subTest(path=path):
                self.assertEqual(propose(payload(PHP, path))["decision"], "HUMAN_REVIEW")

    def test_no_matching_or_ambiguous_fixes(self):
        for source in (
                PHP.replace("flase", "false"),
                PHP.replace("    return flase;", "    return flase;\n    return flase;"),
                PHP.replace("return flase;", "return flase;\n    return ture;"),
                PHP.replace("    return flase;", "// return flase;"),
                PHP.replace("    return flase;", "    return flase; // inline")):
            with self.subTest(source=source):
                self.assertEqual(propose(payload(source))["decision"], "HUMAN_REVIEW")

    def test_schema_and_untrusted_identity(self):
        base = payload()
        cases = [
            {**base, "repository": "some/other"}, {**base, "issue": True},
            {**base, "issue": 0}, {**base, "head_sha": "bad"},
            {**base, "base_blob_sha": "bad"}, {**base, "base_blob_sha": "0" * 40},
            {**base, "source": PHP + "unexpected"}, {**base, "source": 123},
            {**base, "source": PHP + "\x00"}, {**base, "source": PHP.replace("\n", "\r\n")},
            {**base, "source": PHP * 1500}, {**base, "source": PHP.rstrip("\n")},
            {**base, "extra": "injection"}, {k: v for k, v in base.items() if k != "issue"},
            None, [], "string",
        ]
        for x in cases:
            with self.subTest(case=str(x)[:120]):
                self.assertEqual(propose(x)["decision"], "HUMAN_REVIEW")

    def test_cli_json_and_invalid_input(self):
        for data, expected in ((json.dumps(payload()), "PROPOSED_ONLY"), ("{bad", "HUMAN_REVIEW")):
            proc = subprocess.run(
                [sys.executable, "-B", "-m", "scripts.development_codefix_candidate"],
                input=data, text=True, capture_output=True, check=False,
            )
            self.assertEqual(json.loads(proc.stdout)["decision"], expected)
            self.assertEqual(proc.returncode, 0 if expected == "PROPOSED_ONLY" else 2)
            self.assertEqual(proc.stderr, "")

    def test_no_source_execution_or_auto_apply(self):
        # Untrusted source is merely a string; it must never be evaluated.
        source = PHP.replace("flase", "false") + "<?php system('touch /tmp/NEVER');\n"
        self.assertEqual(propose(payload(source))["decision"], "HUMAN_REVIEW")
        report = propose(payload())
        self.assertNotIn("candidate_content", report)
        self.assertNotIn("authorization", report)


if __name__ == "__main__":
    unittest.main()
