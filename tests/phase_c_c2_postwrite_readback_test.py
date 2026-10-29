import unittest
from scripts.phase_c_c2_postwrite_readback import verify_refs, authorize_or_write, CANDIDATE
from scripts.phase_c_c2_owner_gate import HEAD, BRANCH, NONCE


def sample():
    return ({"ref": BRANCH, "object": {"type": "commit", "sha": CANDIDATE}},
            {"ref": NONCE, "object": {"type": "commit", "sha": HEAD}})


class ReadbackTests(unittest.TestCase):
    def test_matching_immutable_refs_still_cannot_write(self):
        x, y = sample()
        r = verify_refs(x, y)
        self.assertEqual(r["decision"], "READBACK_MATCH")
        self.assertFalse(r["write_allowed"])
        self.assertFalse(r["retry_allowed"])

    def test_each_wrong_ref_or_target_fails_closed(self):
        for index, field, value in [
                (0, "ref", "refs/heads/main"),
                (1, "ref", "refs/tags/other"),
                (0, "sha", HEAD),
                (1, "sha", CANDIDATE),
                (0, "type", "tag"),
                (1, "type", "blob")]:
            x, y = sample()
            item = [x, y][index]
            if field in ("sha", "type"):
                item["object"][field] = value
            else:
                item[field] = value
            self.assertEqual(verify_refs(x, y)["decision"], "DENY")

    def test_no_credential_or_network_entrypoint(self):
        self.assertEqual(authorize_or_write(token="fake")["decision"], "DENY")
        self.assertEqual(verify_refs({}, {})["decision"], "DENY")


if __name__ == "__main__":
    unittest.main()
