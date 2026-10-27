import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
from scripts import prepare_shared_ovh_manifest as prep


class ReleaseRequestTest(unittest.TestCase):
    def test_valid_and_invalid_request(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / "release-requests").mkdir()
            path = root / "release-requests" / "website-beheer.json"
            path.write_text(json.dumps({"schema": 1, "paths": [
                "private/bootstrap.php", "catalogus-public/admin/admin.css"
            ]}))
            with patch.object(prep, "ROOT", root):
                self.assertEqual(len(prep.request_paths("website-beheer")), 2)
                path.write_text(json.dumps({"schema": 1, "paths": ["private/../config.php"]}))
                with self.assertRaises(ValueError):
                    prep.request_paths("website-beheer")
                with self.assertRaises(ValueError):
                    prep.request_paths("../website-beheer")


if __name__ == "__main__":
    unittest.main()
