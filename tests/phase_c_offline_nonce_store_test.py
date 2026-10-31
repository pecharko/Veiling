import concurrent.futures
import tempfile
import unittest
from pathlib import Path
from scripts.phase_c_offline_nonce_store import OfflineNonceStore


class OfflineNonceStoreTests(unittest.TestCase):
    def test_one_winner_across_instances_and_process_lifetimes(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "nonces.sqlite3"
            store = OfflineNonceStore(path)
            with concurrent.futures.ThreadPoolExecutor(max_workers=12) as pool:
                results = list(pool.map(lambda _: OfflineNonceStore(path).reserve_once(12, 13), range(16)))
            self.assertEqual(results.count(True), 1)
            self.assertEqual(results.count(False), 15)
            self.assertFalse(store.reserve_once(12, 13))
            self.assertTrue(OfflineNonceStore(path).reserve_once(12, 14))

    def test_invalid_identifiers_fail_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            store = OfflineNonceStore(Path(directory) / "n.db")
            for bad in (False, 0, -1, "1", None):
                with self.subTest(bad=bad):
                    self.assertFalse(store.reserve_once(bad, 1))


if __name__ == "__main__":
    unittest.main()
