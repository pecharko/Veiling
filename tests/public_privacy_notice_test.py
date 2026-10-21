#!/usr/bin/env python3
"""Check public privacy link availability and prevent unreviewed legal claims."""
from pathlib import Path
import unittest
root=Path(__file__).resolve().parents[1]
page=(root/"catalogus-public/privacy.php").read_text()
home=(root/"catalogus-public/index.php").read_text()
lot=(root/"catalogus-public/kavel.php").read_text()
class PublicPrivacyTests(unittest.TestCase):
    def test_footer_links(self):
        for src in (home,lot):
            self.assertIn("url('privacy.php')",src)
    def test_truthful_text_and_contacts(self):
        self.assertIn("gepseudonimiseerde", page)
        self.assertIn("geen automatische opschoning",page)
        self.assertIn("filter_var($contact, FILTER_VALIDATE_EMAIL)",page)
        self.assertIn("rechtsgrond en concrete maximale bewaartermijn",page)
        self.assertIn("interne kavel-ID",page)
    def test_analytics_code_untouched(self):
        source=(root/"private/visitor_stats.php").read_text()
        self.assertNotIn("DELETE FROM visitor_stats_daily",source)
if __name__=="__main__":
    unittest.main()
