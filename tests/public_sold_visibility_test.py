"""Regression checks for sold-lot public visibility (no production DB needed)."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1] / "catalogus-public"


class SoldVisibilityTest(unittest.TestCase):
    def test_catalog_filters_include_sold_but_exclude_canceled(self):
        source = (ROOT / "index.php").read_text()
        self.assertIn("l.status IN ('published','sold')", source)
        self.assertIn("status IN ('published','sold')", source)
        self.assertIn("$lot['status'] === 'sold'", source)
        self.assertIn('class="lot-sold-badge"', source)

    def test_detail_and_navigation_include_sold(self):
        source = (ROOT / "kavel.php").read_text()
        self.assertIn("status IN ('published','sold')", source)
        self.assertIn("detail-sold-badge", source)
        self.assertIn("Verkocht", source)
        self.assertNotIn("status='published'", source)

    def test_sold_style_exists(self):
        source = (ROOT / "assets/catalogus.css").read_text()
        self.assertIn(".lot-cover .lot-sold-badge", source)
        self.assertIn(".detail-sold-badge", source)
        self.assertIn("rotate(30deg)", source)
        self.assertIn("#cfe3cf", source)
        self.assertIn(".lot-badge{border-radius:8px}", source)


if __name__ == "__main__":
    unittest.main()
