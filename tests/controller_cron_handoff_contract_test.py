import hashlib
import hmac
import json
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_cron_handoff_contract import assess_handoff, canonical

KEY = b"test-key-only-" * 4
PAYLOAD = dict(repository="pecharko/Veiling", issue_id=7, comment_id=11,
               release_id="release-demo", release_sha="a"*40, issued_at=1000,
               expires_at=1200, nonce="b"*64)

def mac(p):
    return hmac.new(KEY, canonical(p), hashlib.sha256).hexdigest()

class Handoff(unittest.TestCase):
    def check_block(self, p, s, **kwargs):
        result = assess_handoff(p, s, KEY, now=1050, **kwargs)
        self.assertEqual(result["status"], "BLOCKED")
        self.assertIs(result["write_allowed"], False)
        self.assertIs(result["dispatch_allowed"], False)
        self.assertIs(result["automatic_retry_allowed"], False)
        return result

    def test_valid_never_authorizes_writes(self):
        self.assertEqual(self.check_block(PAYLOAD,mac(PAYLOAD))["reason"],
                         "independent_restore_safe_replay_proof_missing")
        self.assertEqual(self.check_block(PAYLOAD,mac(PAYLOAD),independent_replay_proven=True)["reason"],
                         "production_executor_not_authorized")

    def test_tamper_and_expiry(self):
        p={**PAYLOAD,"comment_id":12}
        self.assertEqual(self.check_block(p,mac(PAYLOAD))["reason"],"invalid_handoff")
        self.assertEqual(assess_handoff(PAYLOAD,mac(PAYLOAD),KEY,now=1201)["status"],"BLOCKED")
        self.assertEqual(assess_handoff(PAYLOAD,mac(PAYLOAD),KEY,now=900)["status"],"BLOCKED")

    def test_unknown_fields_and_bad_identity(self):
        for p in ({**PAYLOAD,"dispatch_allowed":True},
                  {**PAYLOAD,"repository":"someone/Other"},
                  {**PAYLOAD,"nonce":"z"*64},
                  {**PAYLOAD,"expires_at":2000}):
            self.assertEqual(self.check_block(p,"0"*64)["reason"],"invalid_handoff")

    def test_bad_secret(self):
        self.assertFalse(assess_handoff(PAYLOAD,mac(PAYLOAD),b"weak",now=1050)["write_allowed"])

if __name__=="__main__":
    unittest.main()
