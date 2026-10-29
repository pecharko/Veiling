import unittest
from scripts.phase_c_one_shot_preflight import check_one_shot_preflight


def sample():
    approval = dict(issue_id=1, comment_id=2, pr_number=3, head_sha="a"*40,
                    blob_sha="b"*40, new_sha256="c"*64,
                    target_path="tests/phase_c_sandbox_test.php")
    current = dict(head_sha="a"*40, blob_sha="b"*40,
                   target_path="tests/phase_c_sandbox_test.php",
                   owner_verified=True, nonce_reserved_atomically=True,
                   nonce_replayed=False, pr_is_open=True, from_fork=False)
    return approval, current


class OneShotPreflightTests(unittest.TestCase):
    def test_simulated_success_never_grants_writes(self):
        r = check_one_shot_preflight(*sample())
        self.assertEqual(r["decision"], "READY_FOR_TRUSTED_WRITER_REVIEW")
        self.assertFalse(r["can_write"])
        self.assertFalse(r["can_dispatch"])
        self.assertFalse(r["can_merge"])

    def test_drift_replay_unverified_or_missing_atomic_reservation_denied(self):
        for key, value in (("head_sha", "f"*40), ("blob_sha", "e"*40),
                           ("nonce_replayed", True), ("nonce_replayed", None),
                           ("nonce_reserved_atomically", False),
                           ("owner_verified", False), ("from_fork", True),
                           ("pr_is_open", False)):
            with self.subTest(key=key):
                a, c = sample()
                c[key] = value
                self.assertEqual(check_one_shot_preflight(a, c)["decision"], "DENY")
        a, c = sample()
        a["comment_id"] = False
        self.assertEqual(check_one_shot_preflight(a, c)["decision"], "DENY")

    def test_malformed_and_unexpected_target_denied(self):
        self.assertEqual(check_one_shot_preflight(None, {})["decision"], "DENY")
        a, c = sample()
        a["target_path"] = ".github/workflows/deploy.yml"
        self.assertEqual(check_one_shot_preflight(a, c)["decision"], "DENY")


if __name__ == "__main__":
    unittest.main()
