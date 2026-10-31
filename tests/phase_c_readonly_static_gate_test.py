"""Adversarial tests for the Phase C read-only static boundary guard."""
import unittest
from pathlib import Path

from scripts.phase_c_readonly_static_gate import (
    CLIENT, MODULES, WORKFLOW, check_repository,
    review_module, review_sources, review_workflow,
)


class PhaseCStaticGateTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.root = Path(__file__).resolve().parent.parent
        cls.sources = {
            path: (cls.root / path).read_text(encoding="utf-8")
            for path in MODULES
        }
        cls.workflow = (cls.root / WORKFLOW).read_text(encoding="utf-8")

    def test_checkout_meets_current_static_guard(self):
        self.assertEqual(check_repository(self.root), [])
        self.assertEqual(review_sources(self.sources, self.workflow), [])

    def test_privileged_flags_must_not_be_enabled(self):
        for flag in ("write_allowed", "can_write", "retry_allowed",
                     "merge_allowed", "can_merge", "dispatch_allowed",
                     "production_authorized", "ready_for_activation"):
            with self.subTest(flag=flag):
                src = self.sources["scripts/phase_c_atomic_refs_plan.py"]
                src += "\ndef unsafe():\n    return {" + repr(flag) + ": True}\n"
                self.assertTrue(review_module(
                    "scripts/phase_c_atomic_refs_plan.py", src
                ))

    def test_unsafe_http_methods_and_host_refused(self):
        source = self.sources[CLIENT]
        for replacement, expected in (
            ('method="POST"', "request not strict GET"),
            ('method="PATCH"', "request not strict GET"),
            ('method="DELETE"', "request not strict GET"),
            ('method="GET", data=b"X"', "request not strict GET"),
        ):
            with self.subTest(method=replacement):
                changed = source.replace('method="GET"', replacement)
                errors = review_module(CLIENT, changed)
                self.assertTrue(any(expected in x for x in errors), errors)
        changed = source.replace(
            'API = "https://api.github.com"',
            'API = "https://attacker.invalid"',
        )
        self.assertTrue(any(
            "host changed" in x or "client constant drift: API" in x
            for x in review_module(CLIENT, changed)
        ))

    def test_direct_network_or_shell_calls_rejected(self):
        path = "scripts/phase_c_atomic_ref_reconciliation.py"
        for payload in (
            "\nimport subprocess\n",
            "\nimport socket\n",
            "\nimport os\n",
            "\nimport requests\n",
            "\ndef unsafe():\n    return requests.post('https://example.com')\n",
            "\ndef unsafe():\n    return eval('1+1')\n",
            "\ndef unsafe():\n    return compile('1+1', 'sandbox', 'eval')\n",
            "\ndef unsafe():\n    return urllib.request.urlopen('https://example.com')\n",
        ):
            with self.subTest(payload=payload.strip()[:60]):
                self.assertTrue(review_module(path, self.sources[path] + payload))

    def test_transport_cannot_be_added_outside_dedicated_reader(self):
        path = "scripts/phase_c_complete_readonly_audit.py"
        content = self.sources[path] + (
            "\nfrom urllib.request import Request\n"
            "\ndef unsafe():\n    Request('https://example.com', method='POST')\n"
        )
        errors = review_module(path, content)
        self.assertTrue(errors)
        self.assertTrue(any("outside" in error for error in errors))

    def test_readonly_client_cannot_gain_additional_network_methods(self):
        source = self.sources[CLIENT] + (
            "\ndef bad():\n    self._opener.open('https://example.com')\n"
        )
        self.assertTrue(any("transport shape changed" in x
                            for x in review_module(CLIENT, source)))
        changed = self.sources[CLIENT].replace(
            '    def get(self, path):',
            '    def post(self, path):\n        return True\n\n    def get(self, path):',
        )
        self.assertTrue(any("public methods" in x
                            for x in review_module(CLIENT, changed)))

    def test_hidden_urllib_transport_import_aliases_rejected(self):
        source = self.sources[CLIENT]
        for injection in (
            "from urllib.request import Request as HiddenRequest",
            "from urllib.request import urlopen",
            "import urllib.request as unsafe_transport",
            "from urllib.error import HTTPError as AliasError",
        ):
            with self.subTest(injection=injection):
                self.assertTrue(review_module(CLIENT, source + "\n" + injection + "\n"))

    def test_client_get_allowlist_and_fixed_names_cannot_expand(self):
        source = self.sources[CLIENT]
        cases = (
            (
                '    re.escape(ROOT) + r"/git/ref/heads/main",',
                '    re.escape(ROOT) + r"/git/ref/heads/main",\n'
                '    re.escape(ROOT) + r"/actions/runs",',
            ),
            ('ROOT = "/repos/pecharko/Veiling"',
             'ROOT = "/repos/attacker/repo"'),
            ('TARGET = "tests/phase_c_sandbox_test.php"',
             'TARGET = "private/config.php"'),
            ('ID = r"[1-9][0-9]*"', 'ID = r".*"'),
            ('SHA = r"[a-f0-9]{40}"', 'SHA = r".*"'),
            ('BRANCH = r"test/phase-c-[a-z0-9-]{1,40}"',
             'BRANCH = r".*"'),
            (
                'NONCE_PATTERN = re.compile(\n'
                '    re.escape(ROOT) + r"/git/ref/tags/phase-c-once-issue-" + ID\n'
                ')',
                'NONCE_PATTERN = re.compile(r".*")',
            ),
            (
                'API = "https://api.github.com"',
                'API = "https://api.github.com"\n'
                'API = "https://attacker.invalid"',
            ),
        )
        for old, new in cases:
            with self.subTest(old=old[:45]):
                self.assertIn(old, source)
                altered = source.replace(old, new, 1)
                errors = review_module(CLIENT, altered)
                self.assertTrue(
                    any("allowlist" in item or "constant drift" in item
                        or "nonce endpoint" in item for item in errors),
                    errors,
                )

    def test_ci_checkout_token_and_job_permission_escalation_denied(self):
        checkout_marker = '          persist-credentials: false'
        self.assertIn(checkout_marker, self.workflow)
        cases = (
            self.workflow.replace(
                '    runs-on: ubuntu-latest',
                '    permissions:\n      contents: write\n'
                '    runs-on: ubuntu-latest',
                1,
            ),
            self.workflow.replace(
                checkout_marker, '          persist-credentials: true', 1
            ),
            self.workflow.replace(checkout_marker + '\n', '', 1),
            self.workflow.replace(
                checkout_marker,
                checkout_marker + '\n          token: fake-secret',
                1,
            ),
            self.workflow.replace(
                checkout_marker,
                checkout_marker + '\n          persist-credentials: true',
                1,
            ),
            self.workflow.replace(
                '      - name: Set up PHP 8.2',
                '      - name: Extra checkout\n'
                '        uses: actions/checkout@v4\n\n'
                '      - name: Set up PHP 8.2',
                1,
            ),
            self.workflow + '\npermissions:\n  contents: read\n',
        )
        for index, workflow in enumerate(cases):
            with self.subTest(index=index):
                issues = review_workflow(workflow)
                self.assertTrue(issues, "Privilege drift was not detected")

    def test_invalid_syntax_and_missing_files_fail_closed(self):
        path = "scripts/phase_c_atomic_refs_plan.py"
        self.assertTrue(review_module(path, "def broken("))
        items = dict(self.sources)
        del items[path]
        self.assertTrue(any("code missing" in x
                            for x in review_sources(items, self.workflow)))

    def test_ci_permissions_and_guard_required(self):
        self.assertEqual(review_workflow(self.workflow), [])
        for changed in (
            self.workflow.replace("contents: read", "contents: write", 1),
            self.workflow.replace(
                "python3 scripts/phase_c_readonly_static_gate.py", "echo skipped"
            ),
            self.workflow.replace(
                "python3 -m unittest discover -s tests -p phase_c_readonly_static_gate_test.py -v",
                "echo skipped",
            ),
        ):
            with self.subTest(case=len(changed)):
                self.assertTrue(review_workflow(changed))


if __name__ == "__main__":
    unittest.main()
