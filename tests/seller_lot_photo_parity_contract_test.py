"""Offline regression checks for lot-style artist upload controls."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
SELLER = (ROOT / "catalogus-public/admin/verkoper.php").read_text()
JS = (ROOT / "catalogus-public/admin/seller-photo-preview.js").read_text()
CSS = (ROOT / "catalogus-public/admin/admin.css").read_text()


class SellerLotPhotoParityContract(unittest.TestCase):
    def test_seller_editor_has_top_and_bottom_actions(self):
        self.assertIn('form="seller-manager-form"', SELLER)
        self.assertEqual(SELLER.count('class="primary seller-save-trigger"'), 2)
        self.assertIn('seller-editor-cancel', SELLER)
        self.assertIn('seller-form-actions', SELLER)

    def test_separate_artwork_and_portrait_gallery(self):
        self.assertIn('data-seller-gallery="work" data-max-photos="3"', SELLER)
        self.assertIn('data-seller-gallery="portrait" data-max-photos="1"', SELLER)
        self.assertIn('lot-editor-photo-add-picker', SELLER)
        self.assertIn('seller-remove-saved', SELLER)
        self.assertNotIn('seller-photo-delete-grid', SELLER)

    def test_edit_upload_and_remove_use_confirmed_json(self):
        self.assertIn("['upload-photo','delete-photo']", SELLER)
        self.assertIn("'image_url'=>url((string)$uploaded['path'])", SELLER)
        self.assertIn("'deleted'=>true", SELLER)
        self.assertIn("await send('upload-photo'", JS)
        self.assertIn("await send('delete-photo'", JS)
        self.assertIn("!result.image_url.startsWith('/')", JS)
        self.assertIn("credentials:'same-origin'", JS)

    def test_new_seller_stages_files_in_form(self):
        self.assertIn("if (!sellerId)", JS)
        self.assertIn("input.name='photo_' + kind + '_' + slot", JS)
        self.assertIn("input.parentNode.append(freshInput)", JS)
        self.assertIn('tile.append(input)', JS)
        self.assertIn('seller_image_upload($pdo,$savedId,$kind,$slot', SELLER)

    def test_invalid_or_ambiguous_photo_upload_cannot_autoretry(self):
        self.assertIn("failure=true;", JS)
        self.assertIn("queuedSave=false;", JS)
        self.assertNotIn('setTimeout(', JS)
        self.assertIn("event.stopImmediatePropagation()", JS)
        self.assertIn('seller-photo-upload-error', CSS)


if __name__ == "__main__":
    unittest.main()
