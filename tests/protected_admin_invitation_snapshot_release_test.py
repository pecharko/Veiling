import unittest
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest.mock import patch
from scripts import deploy_protected_admin_invitation_snapshot as p

class ProtectedSnapshotTest(unittest.TestCase):
    def test_exact_fixed_target(self):
        self.assertEqual(p.TARGET, "private/snapshots.php")
        self.assertEqual(len(p.SOURCE_SHA), 64)
        self.assertEqual(len(p.REMOTE_SHA), 64)

    def test_changed_source_is_rejected(self):
        with TemporaryDirectory() as tmp:
            target = Path(tmp) / p.TARGET
            target.parent.mkdir()
            target.write_bytes(b"modified")
            with self.assertRaises(ValueError):
                p.guarded_load(p.RELEASE_ID, source_root=Path(tmp))

    def test_plan_entrypoint_initializes_engine_without_deployment(self):
        # Regression: real main() initialization must not hide scripts_dir
        # inside a comment or reach the production deploy implementation.
        with patch("sys.argv", ["adapter", "plan"]), patch.object(p.engine, "run") as run:
            p.main()
            run.assert_called_once()

    def test_other_release_is_rejected(self):
        with self.assertRaises(ValueError):
            p.guarded_load("other")

if __name__ == "__main__":
    unittest.main()
