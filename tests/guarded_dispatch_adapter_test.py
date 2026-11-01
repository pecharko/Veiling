"""Offline contract and fail-closed tests of dormant dispatch handoff."""
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from guarded_dispatch_adapter import prepare, dispatch, verify_receipt, ENABLED

def sample():
    t={"schema":1,"repository":"pecharko/Veiling","owner":"pecharko",
       "release_id":"test-release","main_sha":"a"*40,"manifest_sha256":"b"*64,
       "nonce":"c"*64,"approved_scope":["catalogus-public/index.php"]}
    e={k:t[k] for k in ("main_sha","manifest_sha256","release_id","approved_scope")}
    e.update({k:True for k in ("owner_verified","ci_verified","manifest_verified",
                               "baseline_verified","nonce_unused","no_prior_dispatch",
                               "no_concurrent_deployment","main_current")})
    return t,e

class ContractTests(unittest.TestCase):
    def test_disabled_even_after_claimed_perfect_evidence(self):
        t,e=sample()
        self.assertFalse(ENABLED)
        self.assertEqual(prepare(t,e)["status"],"REVIEW_REQUIRED")
        self.assertFalse(prepare(t,e)["dispatch_attempted"])
        self.assertEqual(dispatch(t,e,token="fake",enabled=True)["status"],"DENIED")
    def test_missing_evidence_rejected(self):
        t,e=sample()
        e["ci_verified"]=False
        self.assertEqual(prepare(t,e)["status"],"DENIED")
    def test_ambiguous_request_no_retry(self):
        t,_=sample()
        for receipt in (None, {}, {"release_id":"test-release","main_sha":"a"*40,
                                     "attempted":True,"confirmed":False}):
            with self.subTest(receipt=receipt):
                r=verify_receipt(receipt,t["release_id"],t["main_sha"])
                self.assertEqual(r["status"],"AMBIGUOUS_STOP_NO_RETRY")
                self.assertFalse(r["retry_allowed"])
    def test_claimed_run_requires_readback(self):
        r=verify_receipt({"release_id":"test-release","main_sha":"a"*40,
                          "attempted":True,"confirmed":True,"run_id":123},
                         "test-release","a"*40)
        self.assertEqual(r["status"],"READBACK_REQUIRED")
        self.assertFalse(r["retry_allowed"])
        self.assertFalse(r["dispatch_attempted"])

if __name__ == "__main__":
    unittest.main()
