"""Previous-edition photos must be static, without captions or a lightbox."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
PAGE = ROOT / "catalogus-public" / "vorige-editie.php"
CSS = ROOT / "catalogus-public" / "assets" / "vorige-editie.css"


class PreviousEditionStaticPhotos(unittest.TestCase):
    def test_photos_render_without_interactive_wrapper(self):
        page = PAGE.read_text(encoding="utf-8")
        self.assertIn('class="impression-photo-image"', page)
        self.assertIn('alt="<?=e($alt)?>"', page)
        self.assertNotIn('impression-photo-link', page)
        self.assertNotIn('data-impression-photo', page)
        self.assertNotIn('<figcaption', page)
        self.assertNotIn('impression-enlarge', page)
        self.assertNotIn('Vergroot foto:', page)

    def test_no_click_to_zoom_lightbox_or_instruction(self):
        page = PAGE.read_text(encoding="utf-8")
        for term in ('impression-viewer', 'vorige-editie.js', 'Klik op een foto',
                     'impression-hint', '<dialog'):
            with self.subTest(term=term):
                self.assertNotIn(term, page)

    def test_css_retains_static_photo_aspect_ratios(self):
        css = CSS.read_text(encoding="utf-8")
        self.assertIn('.impression-cover .impression-photo-image{aspect-ratio:4/3}', css)
        self.assertIn('.impression-pair .impression-photo-image{aspect-ratio:16/9}', css)
        self.assertIn('.impression-gallery-grid .impression-photo-image{aspect-ratio:4/3}', css)
        self.assertNotIn(':hover img', css)
        self.assertNotIn('.impression-viewer', css)
        self.assertNotIn('.impression-enlarge', css)


if __name__ == "__main__":
    unittest.main()
