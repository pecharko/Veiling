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
        self.assertTrue(any("host changed" in x
                            for x in review_module(CLIENT, changed)))

    def test_direct_network_or_shell_calls_rejected(self):
        path = "scripts/phase_c_atomic_ref_reconciliation.py"
        for payload in (
            "\nimport subprocess\n",
            "\nimport socket\n",
            "\nimport os\n",
            "\nimport requests\n",
            "\ndef unsafe():\n    return requests.post('https://example.com')\n",
            "\ndef unsafe():\n    return eval('1+1')\n",
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
