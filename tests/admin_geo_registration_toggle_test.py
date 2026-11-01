"""Regression contract: general visitor logging dominates geography toggle."""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ADMIN = (ROOT / "catalogus-public/admin/statistieken.php").read_text()
STATE = (ROOT / "private/visitor_geo_state.php").read_text()
TRACK = (ROOT / "private/visitor_stats.php").read_text()
DASH = (ROOT / "private/visitor_geo_dashboard.php").read_text()

class AdminGeoToggleTest(unittest.TestCase):
    def test_authenticated_csrf_action(self):
        self.assertIn("require_auth();", ADMIN)
        self.assertIn("check_csrf();", ADMIN)
        self.assertIn("'toggle_geo_collection'", ADMIN)
        self.assertIn('name="action" value="toggle_geo_collection"', ADMIN)
        self.assertIn("visitor_geo_set_enabled(db())", ADMIN)
        self.assertIn("visitor_geo_set_disabled()", ADMIN)
    def test_general_off_explicitly_disables_geo(self):
        self.assertIn("if ($enabled === '0') visitor_geo_set_disabled();", ADMIN)
        self.assertIn("set_analytics_collection_enabled($enabled === '1');", ADMIN)
    def test_geo_cannot_enable_without_general_registration(self):
        self.assertIn("if (!analytics_collection_enabled())", STATE)
        self.assertIn("&& analytics_collection_enabled()", STATE)
        self.assertIn("!is_file(visitor_geo_disabled_marker())", STATE)
        self.assertIn("!$collectionEnabled || $geoStatusError", ADMIN)
    def test_no_legacy_geo_backfill_or_data_deletion(self):
        self.assertIn("UPDATE visitor_geo_state SET start_date = UTC_DATE()", STATE)
        self.assertNotIn("DELETE FROM visitor_geo_", STATE)
        self.assertNotIn("TRUNCATE", STATE)
        self.assertIn("Bestaande geografische gegevens blijven bewaard", ADMIN)
    def test_collection_and_dashboard_guarded(self):
        self.assertIn("visitor_geo_collection_enabled($state)", TRACK)
        self.assertIn("visitor_geo_collection_enabled($state)", DASH)
        self.assertIn("visitor_geo_covers_day($state, $day)", TRACK)
    def test_explicit_user_controls(self):
        self.assertIn("Geostatistieken inschakelen", ADMIN)
        self.assertIn("Geostatistieken uitschakelen", ADMIN)
        self.assertIn("csrf_field()", ADMIN)

if __name__ == "__main__":
    unittest.main()
