"""Contract checks for the bounded manual archive admin action."""
from pathlib import Path
import unittest

SOURCE = (Path(__file__).resolve().parents[1] / "catalogus-public/admin/statistieken.php").read_text()

class ManualArchiveBatchTest(unittest.TestCase):
    def test_explicit_two_step_csrf_flow(self):
        self.assertIn("check_csrf();", SOURCE)
        self.assertIn("analytics_archive_pending", SOURCE)
        self.assertIn("hash_equals($pending['token']", SOURCE)
        self.assertIn("'ARCHIVEER'", SOURCE)

    def test_bounded_three_day_batch_uses_guarded_transaction(self):
        self.assertIn("for ($i = 0; $i < 3; ++$i)", SOURCE)
        self.assertIn("archive_visitor_stats_one_day(db(), (string)$pending['cutoff'])", SOURCE)
        self.assertIn("if ($result['days'] === 0) break;", SOURCE)
        self.assertIn("Eerder voltooide dagen blijven veilig gearchiveerd", SOURCE)

    def test_explicit_ui_and_retention(self):
        self.assertIn("Handmatige archivering starten", SOURCE)
        self.assertIn("Archivering nu starten", SOURCE)
        self.assertIn("maximaal 3 afgesloten UTC-dagen ouder dan 3 dagen", SOURCE)
        self.assertIn("De dagelijkse OVH-cron blijft onafhankelijk actief", SOURCE)

if __name__ == "__main__":
    unittest.main()
