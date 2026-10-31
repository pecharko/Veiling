"""Offline immutable sandbox fixture digest checks; no GitHub API."""
import unittest
from scripts.phase_c_fixture_digest_verifier import (
    PATH, ORIGINAL_HEAD, ORIGINAL_BLOB, verify_fixture,
)

ORIGINAL = "<?php\ndeclare(strict_types=1);\n// Isolated Phase C exercise ONLY: intentionally unresolved typo.\n// Never merge this draft PR or route this file into the application.\nfunction phase_c_sandbox_example(): bool\n{\n    return flase;\n}\n".encode("utf-8")


class DigestContractTests(unittest.TestCase):
    def verify(self, data=ORIGINAL, **overrides):
        opts = dict(path=PATH, head=ORIGINAL_HEAD, original_blob=ORIGINAL_BLOB)
        opts.update(overrides)
        return verify_fixture(data, **opts)

    def test_exact_fixture(self):
        result = self.verify()
        self.assertTrue(result["verified"])
        self.assertFalse(result["write_allowed"])

    def test_reject_mutated_fixture_and_duplicate_typo(self):
        for data in (ORIGINAL + b" ", ORIGINAL.replace(b"flase", b"false"),
                     ORIGINAL + b"return flase;", b"", None):
            with self.subTest(data=data):
                self.assertFalse(self.verify(data)["verified"])

    def test_reject_identity_or_path_drift(self):
        for changed in ({"path": "catalogus-public/index.php"},
                        {"head": "0" * 40}, {"original_blob": "f" * 40}):
            with self.subTest(changed=changed):
                self.assertFalse(self.verify(**changed)["verified"])


if __name__ == "__main__":
    unittest.main()
