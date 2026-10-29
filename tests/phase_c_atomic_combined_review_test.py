import unittest
from scripts.phase_c_atomic_combined_review import propose_atomic_combined, NONCE, SANDBOX
from scripts.phase_c_atomic_refs_plan import MUTATION, ZERO_SHA
from scripts.phase_c_disabled_writer import Transaction


def staged():
    t = Transaction()
    def packet(label, update):
        return {"query": MUTATION, "variables": {"input": {
            "repositoryId": "R_kgDOVNLxvg",
            "clientMutationId": "phase-c-"+label+"-427-123-"+t.head[:12],
            "refUpdates": [update]}}}
    a = packet("reserve", {"name": NONCE, "beforeOid": ZERO_SHA, "afterOid": t.head, "force": False})
    b = packet("advance", {"name": SANDBOX, "beforeOid": t.head, "afterOid": "b"*40, "force": False})
    return a,b


class CombinedReviewTests(unittest.TestCase):
    def test_combined_packet_never_authorizes(self):
        a,b=staged()
        r=propose_atomic_combined(a,b)
        self.assertEqual(r["decision"],"REVIEW_ONLY")
        self.assertFalse(r["write_allowed"])
        self.assertFalse(r["network_allowed"])
        self.assertFalse(r["retry_allowed"])
        self.assertFalse(r["activation_allowed"])
        updates=r["envelope"]["variables"]["input"]["refUpdates"]
        self.assertEqual(len(updates),2)
        self.assertEqual(updates[0]["beforeOid"],ZERO_SHA)
        self.assertEqual(updates[1]["beforeOid"],updates[0]["afterOid"])
        self.assertTrue(all(x["force"] is False for x in updates))

    def test_mismatch_denied(self):
        for change in ("branch","force","nonce","before"):
            with self.subTest(change=change):
                a,b=staged()
                if change=="branch": b["variables"]["input"]["refUpdates"][0]["name"]="refs/heads/main"
                if change=="force": b["variables"]["input"]["refUpdates"][0]["force"]=True
                if change=="nonce": a["variables"]["input"]["refUpdates"][0]["name"]="refs/tags/other"
                if change=="before": a["variables"]["input"]["refUpdates"][0]["beforeOid"]="a"*40
                self.assertEqual(propose_atomic_combined(a,b)["decision"],"DENY")

    def test_missing_input_denied(self):
        a,b=staged()
        self.assertEqual(propose_atomic_combined({},b)["decision"],"DENY")
        self.assertEqual(propose_atomic_combined(a,{})["decision"],"DENY")


if __name__=="__main__": unittest.main()
