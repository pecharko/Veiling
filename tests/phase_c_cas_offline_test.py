import hashlib
import unittest
from scripts.phase_c_cas_offline import simulate_cas_transaction

BEFORE = "<?php function smoke() { return flase; }"
AFTER = BEFORE.replace("return flase;", "return false;")


def data():
    a = {"head_sha":"a"*40, "blob_sha":"b"*40,
         "target_path":"tests/phase_c_sandbox_test.php",
         "result_sha256": hashlib.sha256(AFTER.encode()).hexdigest()}
    s = {"head_sha":"a"*40, "blob_sha":"b"*40, "content":BEFORE}
    p = {"observed_head_sha":"a"*40, "observed_blob_sha":"b"*40,
         "path":"tests/phase_c_sandbox_test.php", "content":AFTER,
         "nonce_reserved_atomically":True}
    return a,s,p


class CasOfflineTests(unittest.TestCase):
    def test_success_is_never_a_real_write_grant(self):
        r = simulate_cas_transaction(*data())
        self.assertTrue(r["accepted"])
        self.assertFalse(r["can_write"])
        self.assertFalse(r["can_merge"])

    def test_drift_and_wrong_content_fail_closed(self):
        for key,value in (("observed_head_sha","c"*40),
                          ("observed_blob_sha","c"*40),
                          ("path",".github/workflows/deploy.yml"),
                          ("content","<?php return true;"),
                          ("nonce_reserved_atomically",False)):
            with self.subTest(key=key):
                a,s,p=data()
                p[key]=value
                self.assertFalse(simulate_cas_transaction(a,s,p)["accepted"])
        a,s,p=data()
        s["content"]=BEFORE+BEFORE
        self.assertFalse(simulate_cas_transaction(a,s,p)["accepted"])


if __name__ == "__main__":
    unittest.main()
