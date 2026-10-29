import unittest
from scripts.phase_c_c2_disabled_executor import (
    TrustedContext, assess_boundary, execute, WRITER_ENABLED,
)
from scripts.phase_c_disabled_writer import Transaction
from scripts.phase_c_atomic_refs_plan import MUTATION, ZERO_SHA


def envelopes():
    head = Transaction().head
    repository = "R_kgDOVNLxvg"
    def entry(label, ref):
        return {"query": MUTATION, "variables": {"input": {
            "repositoryId": repository,
            "clientMutationId": "phase-c-" + label + "-427-123-" + head[:12],
            "refUpdates": [ref]}}}
    first = entry("reserve", {"name": Transaction().nonce,
                              "beforeOid": ZERO_SHA, "afterOid": head, "force": False})
    second = entry("advance", {"name": "refs/heads/" + Transaction().branch,
                              "beforeOid": head, "afterOid": "b" * 40, "force": False})
    return first, second


def context(**changes):
    d = dict(repository="pecharko/Veiling", installation_id=176547610,
             pr_number=426, issue_number=427, owner_authenticated=True,
             comment_authenticated=True, rulesets_effective_for_writer=True,
             one_shot_authorized=True, pinned_main_commit="a" * 40)
    d.update(changes)
    return TrustedContext(**d)


class BoundaryTests(unittest.TestCase):
    def test_fully_positive_claims_still_deny(self):
        self.assertIs(WRITER_ENABLED, False)
        a, b = envelopes()
        result = assess_boundary(context(), Transaction(), a, b)
        self.assertEqual(result["decision"], "REVIEW_ONLY")
        for name in ("write_allowed", "network_allowed", "retry_allowed",
                     "dispatch_allowed", "production_authorized"):
            self.assertFalse(result[name])

    def test_claims_and_scope_mismatch_deny(self):
        a, b = envelopes()
        for changed in ({"repository": "other/repo"}, {"installation_id": 1},
                        {"pr_number": 1}, {"issue_number": 1},
                        {"owner_authenticated": False},
                        {"comment_authenticated": False},
                        {"rulesets_effective_for_writer": False},
                        {"one_shot_authorized": False},
                        {"pinned_main_commit": "bad"}):
            with self.subTest(changed=changed):
                self.assertEqual(assess_boundary(context(**changed), Transaction(), a, b)["decision"], "DENY")

    def test_bad_envelope_denied(self):
        a, b = envelopes()
        b["variables"]["input"]["refUpdates"][0]["force"] = True
        self.assertEqual(assess_boundary(context(), Transaction(), a, b)["decision"], "DENY")

    def test_injected_transport_is_never_called(self):
        class Hostile:
            def __getattr__(self, name):
                raise AssertionError("Should not examine transport")
        r = execute(Hostile(), context(), token="do-not-print")
        self.assertEqual(r["decision"], "DENY")
        self.assertEqual(r["operations"], [])


if __name__ == "__main__":
    unittest.main()
