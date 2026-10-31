import unittest
import json
from scripts.phase_c_atomic_refs_plan import MUTATION, ZERO_SHA
from scripts.phase_c_c2_trusted_transport_seam import DisabledTransport, build_review_packet, execute
from scripts.phase_c_disabled_writer import Transaction


def envelopes():
    t = Transaction()
    def package(label,ref):
        return {"query": MUTATION, "variables":{"input":{
            "repositoryId":"R_kgDOVNLxvg",
            "clientMutationId":"phase-c-"+label+"-427-123-"+t.head[:12],
            "refUpdates":[ref]}}}
    a=package("reserve",{"name":t.nonce,"beforeOid":ZERO_SHA,"afterOid":t.head,"force":False})
    b=package("advance",{"name":"refs/heads/"+t.branch,"beforeOid":t.head,"afterOid":"b"*40,"force":False})
    return a,b


class TransportSeamTests(unittest.TestCase):
    def test_exact_offline_packet_is_not_permission(self):
        a,b=envelopes()
        r=build_review_packet(a,b)
        self.assertEqual(r["decision"],"REVIEW_PACKET_ONLY")
        self.assertFalse(r["network_allowed"])
        self.assertFalse(r["write_allowed"])
        self.assertEqual(json.loads(r["reservation_json"]),a)
        self.assertEqual(json.loads(r["advance_json"]),b)

    def test_force_or_wrong_ref_is_denied(self):
        a,b=envelopes()
        b["variables"]["input"]["refUpdates"][0]["force"]=True
        self.assertEqual(build_review_packet(a,b)["decision"],"DENY")
        a,b=envelopes()
        a["variables"]["input"]["refUpdates"][0]["name"]="refs/heads/main"
        self.assertEqual(build_review_packet(a,b)["decision"],"DENY")

    def test_transport_cannot_use_supplied_token(self):
        port=DisabledTransport()
        r=port.post("https://api.github.com/graphql",{"Authorization":"Bearer secret"})
        self.assertEqual(r["decision"],"DENY")
        self.assertEqual(port.operations,[])
        self.assertEqual(execute(port, token="fake")["operations"],[])


if __name__=="__main__":
    unittest.main()
