import unittest
from scripts.readonly_password_reset_snapshot_baseline import TARGET, compare
class SnapshotBaselineTest(unittest.TestCase):
    def test_fixed_target(self):
        self.assertEqual(TARGET, "private/snapshots.php")
    def test_match(self):
        self.assertTrue(compare(b"abc", b"abc")["match"])
    def test_drift(self):
        self.assertFalse(compare(b"abc", b"def")["match"])
    def test_absent(self):
        self.assertFalse(compare(b"abc", None)["match"])
if __name__ == "__main__":
    unittest.main()
