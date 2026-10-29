import unittest
from scripts.phase_c_disabled_writer import Transaction
from scripts.phase_c_trusted_writer_simulator import FakePort, simulate_once

CANDIDATE = "b" * 40


class TrustedWriterSimulationTests(unittest.TestCase):
    def run_case(self, port, **kwargs):
        r = simulate_once(port, Transaction(), CANDIDATE, **kwargs)
        self.assertFalse(r["write_allowed"])
        self.assertFalse(r["retry_allowed"])
        self.assertFalse(r["production_authorized"])
        return r

    def test_fake_happy_path_is_not_authority(self):
        p = FakePort()
        self.assertEqual(self.run_case(p, independent_evidence=True)["decision"], "SIMULATED_VERIFIED")
        self.assertEqual([e[0] for e in p.events], ["reserve", "advance", "readback"])

    def test_nonce_failure_never_attempts_branch(self):
        for outcome in ("rejected", "timeout", "ambiguous", None):
            p = FakePort(reserve=outcome)
            self.assertEqual(self.run_case(p, independent_evidence=True)["decision"], "STOP_NONCE_UNKNOWN")
            self.assertEqual(len(p.events), 1)

    def test_branch_failure_is_one_attempt_no_retry(self):
        for outcome in ("rejected", "timeout", "ambiguous"):
            p = FakePort(advance=outcome)
            self.assertEqual(self.run_case(p, independent_evidence=True)["decision"], "STOP_BRANCH_UNKNOWN")
            self.assertEqual([e[0] for e in p.events], ["reserve", "advance"])

    def test_failed_readback_never_authorizes(self):
        p = FakePort(verify=False)
        self.assertEqual(self.run_case(p, independent_evidence=True)["decision"], "MANUAL_REVIEW")

    def test_unverified_input_calls_nothing(self):
        for evidence in (False, None, "true"):
            p = FakePort()
            self.assertEqual(self.run_case(p, independent_evidence=evidence)["decision"], "DENY")
            self.assertEqual(p.events, [])
        p = FakePort()
        self.assertEqual(simulate_once(p, Transaction(), "nonsense", independent_evidence=True)["decision"], "DENY")
        self.assertEqual(p.events, [])

    def test_arbitrary_injected_transport_rejected(self):
        class Evil:
            def reserve_nonce(self, *args):
                raise AssertionError("must not call external transport")
        r = simulate_once(Evil(), Transaction(), CANDIDATE, independent_evidence=True)
        self.assertEqual(r["decision"], "DENY")


if __name__ == "__main__":
    unittest.main()
