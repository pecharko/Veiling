"""Phase C trusted-writer protocol simulator, permanently offline.

No network, token, GitHub SDK, environment activation or real mutation.
Only FakePort instances supplied in tests are accepted. A subsequent reviewed
change would be needed for a credential-bearing executor.
"""
from scripts.phase_c_disabled_writer import Transaction, prepare_transaction


class FakePort:
    """Test-only in-memory protocol; NOT a GitHub transport."""
    def __init__(self, *, reserve="confirmed", advance="confirmed", verify=True):
        self.reserve = reserve
        self.advance = advance
        self.verify = verify
        self.events = []

    def reserve_nonce(self, nonce, head):
        self.events.append(("reserve", nonce, head))
        return self.reserve

    def advance_branch(self, branch, before, after):
        self.events.append(("advance", branch, before, after))
        return self.advance

    def readback(self):
        self.events.append(("readback",))
        return self.verify


def simulate_once(port, transaction, candidate_sha, *, independent_evidence=False):
    """Fail closed at every boundary; no operation is authorization."""
    base = {"write_allowed": False, "retry_allowed": False,
            "production_authorized": False, "decision": "DENY"}
    if (type(port) is not FakePort or
            prepare_transaction(transaction).get("decision") != "PREPARED_INACTIVE" or
            independent_evidence is not True or
            type(candidate_sha) is not str or len(candidate_sha) != 40 or
            any(ch not in "0123456789abcdef" for ch in candidate_sha) or
            candidate_sha == transaction.head):
        return dict(base, reason="Untrusted or invalid simulated scope")
    reserve = port.reserve_nonce(transaction.nonce, transaction.head)
    if reserve != "confirmed":
        return dict(base, decision="STOP_NONCE_UNKNOWN", reason="Nonce consumed or uncertain")
    advance = port.advance_branch(transaction.branch, transaction.head, candidate_sha)
    if advance != "confirmed":
        return dict(base, decision="STOP_BRANCH_UNKNOWN", reason="Nonce already spent")
    if port.readback() is not True:
        return dict(base, decision="MANUAL_REVIEW", reason="Readback unverifiable")
    return dict(base, decision="SIMULATED_VERIFIED",
                reason="Fake-port only; never evidence of GitHub write")
