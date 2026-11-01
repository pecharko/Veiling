"""Offline owner-bound dispatch candidate tests. Never invoke a network service."""
import unittest
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from owner_bound_dispatch_review import review, execute

def fixture():
    t = {"schema":1,"repository":"pecharko/Veiling","owner":"pecharko",
         "release_id":"sample-release", "main_sha":"a"*40,
         "manifest_sha256":"b"*64,"nonce":"c"*64,
         "approved_scope":["catalogus-public/index.php"]}
    e = {k:t[k] for k in ("main_sha","manifest_sha256","release_id","approved_scope")}
    e.update({k:True for k in ("owner_verified","ci_verified","manifest_verified",
              "baseline_verified","nonce_unused","no_prior_dispatch",
              "no_concurrent_deployment","main_current")})
    return t,e

class ReviewTests(unittest.TestCase):
    def test_consistent_offline_proof_still_denies_dispatch(self):
        t,e=fixture()
        result=review(t,e)
        self.assertEqual(result["decision"],"READY_FOR_SEPARATE_OWNER_REVIEW")
        self.assertFalse(result["dispatch_allowed"])
        self.assertFalse(result["write_allowed"])
        self.assertFalse(result["network_allowed"])
    def test_gate_fail_closed(self):
        for field in ("owner_verified","ci_verified","manifest_verified",
                      "baseline_verified","nonce_unused","no_prior_dispatch",
                      "no_concurrent_deployment","main_current"):
            t,e=fixture();e[field]=False
            with self.subTest(field=field):
                self.assertEqual(review(t,e)["decision"],"DENY")
    def test_identity_and_scope_drift_fail_closed(self):
        for field,value in (("main_sha","d"*40),("manifest_sha256","f"*64),
                            ("approved_scope",["private/evil.php"]),("release_id","different")):
            t,e=fixture();e[field]=value
            with self.subTest(field=field):
                self.assertEqual(review(t,e)["decision"],"DENY")
    def test_ticket_scope_and_owner_rejected(self):
        for field,value in (("owner","another"),("nonce","short"),
                            ("approved_scope",["../secrets"]),
                            ("repository","other/repo")):
            t,e=fixture();t[field]=value
            with self.subTest(field=field):
                self.assertEqual(review(t,e)["decision"],"DENY")
    def test_never_execute(self):
        self.assertEqual(execute({"anything": True})["decision"],"DENY")

if __name__ == "__main__":
    unittest.main()
