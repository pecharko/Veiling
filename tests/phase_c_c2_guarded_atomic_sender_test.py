import json
import unittest
from scripts.phase_c_c2_guarded_atomic_sender import _post_once_injected, submit_atomic
from scripts.phase_c_atomic_refs_plan import MUTATION, ZERO_SHA
from scripts.phase_c_c2_inert_graphql_envelope import HEAD, NONCE, BRANCH


def packets():
    def wrap(kind, update):
        return {"query": MUTATION, "variables": {"input": {
            "repositoryId": "R_kgDOVNLxvg",
            "clientMutationId": "phase-c-" + kind + "-427-123-" + HEAD[:12],
            "refUpdates": [update]}}}
    return (wrap("reserve", {"name": NONCE, "beforeOid": ZERO_SHA,
                              "afterOid": HEAD, "force": False}),
            wrap("advance", {"name": BRANCH, "beforeOid": HEAD,
                              "afterOid": "b"*40, "force": False}))


class Response:
    def __init__(self, content): self.content = content
    def __enter__(self): return self
    def __exit__(self, *args): pass
    def read(self, size): return self.content


class Tests(unittest.TestCase):
    def test_public_entrypoint_always_deny(self):
        self.assertEqual(submit_atomic(token="secret")["decision"], "DENY")

    def test_exact_one_post_still_needs_readback(self):
        a,b=packets()
        seen=[]
        def fake(req, timeout):
            seen.append(req)
            self.assertEqual(req.get_method(), "POST")
            self.assertEqual(req.full_url, "https://api.github.com/graphql")
            self.assertEqual(timeout,10)
            payload=json.loads(req.data)
            updates=payload["variables"]["input"]["refUpdates"]
            self.assertEqual(len(updates),2)
            self.assertEqual([u["name"] for u in updates],[NONCE,BRANCH])
            self.assertTrue(all(u["force"] is False for u in updates))
            return Response(json.dumps({"data":{"updateRefs":{
                "clientMutationId":payload["variables"]["input"]["clientMutationId"]}}}).encode())
        r=_post_once_injected(a,b,"mock-token",opener=fake)
        self.assertEqual(len(seen),1)
        self.assertEqual(r["decision"],"ACK_NEEDS_READBACK")
        self.assertFalse(r["write_allowed"])
        self.assertFalse(r["retry_allowed"])

    def test_timeout_and_errors_are_terminal(self):
        a,b=packets()
        def boom(*args,**kwargs): raise TimeoutError()
        r=_post_once_injected(a,b,"mock",opener=boom)
        self.assertEqual(r["decision"],"AMBIGUOUS")
        self.assertFalse(r["retry_allowed"])
        for response in ({"errors":[{"message":"failed"}]},
                         {"data":{"updateRefs":{"clientMutationId":"wrong"}}},
                         {"data":None}):
            r=_post_once_injected(a,b,"mock",opener=lambda *args,**kw:
                                  Response(json.dumps(response).encode()))
            self.assertEqual(r["decision"],"AMBIGUOUS")

    def test_invalid_scope_does_not_contact_transport(self):
        a,b=packets()
        b["variables"]["input"]["refUpdates"][0]["force"]=True
        def forbidden(*args,**kwargs): raise AssertionError("Network reached")
        self.assertEqual(_post_once_injected(a,b,"mock",opener=forbidden)["decision"],"DENY")


if __name__=="__main__": unittest.main()
