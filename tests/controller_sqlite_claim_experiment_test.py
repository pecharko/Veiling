"""Real isolated SQLite concurrent-writer tests; no OVH/network/dispatch."""
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import sqlite3
import sys
import tempfile
import threading
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from controller_sqlite_claim_experiment import initialize, try_claim

SHA = "a" * 40

def key(comment=12, issue=11, release="shared-demo"):
    return ("pecharko/Veiling", issue, comment, release, SHA)

class SQLiteAtomicTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.path = str(Path(self.tmp.name) / "claims.db")
        with sqlite3.connect(self.path, isolation_level=None) as db:
            initialize(db)

    def tearDown(self):
        self.tmp.cleanup()

    def claim(self, identity):
        with sqlite3.connect(self.path, timeout=10, isolation_level=None) as db:
            return try_claim(db, identity)

    def test_two_concurrent_writers_one_commit(self):
        barrier = threading.Barrier(2)
        def worker():
            barrier.wait(timeout=5)
            return self.claim(key())
        with ThreadPoolExecutor(max_workers=2) as pool:
            futures = [pool.submit(worker) for _ in range(2)]
            results = [f.result(timeout=15) for f in futures]
        self.assertCountEqual(results,
            ["TEST_COMMITTED_NO_DISPATCH", "UNIQUE_CONFLICT"])

    def test_second_comment_cannot_reclaim_same_release(self):
        self.assertEqual(self.claim(key()), "TEST_COMMITTED_NO_DISPATCH")
        self.assertEqual(self.claim(key(comment=13)), "UNIQUE_CONFLICT")
        self.assertEqual(self.claim(key(comment=14, issue=22)), "UNIQUE_CONFLICT")

    def test_same_comment_cannot_claim_second_release(self):
        self.assertEqual(self.claim(key()), "TEST_COMMITTED_NO_DISPATCH")
        self.assertEqual(self.claim(key(release="another-release")), "UNIQUE_CONFLICT")

    def test_claim_survives_connection_reopen(self):
        self.assertEqual(self.claim(key()), "TEST_COMMITTED_NO_DISPATCH")
        self.assertEqual(self.claim(key()), "UNIQUE_CONFLICT")

    def test_rollback_does_not_consume_claim(self):
        with sqlite3.connect(self.path, isolation_level=None) as db:
            db.execute("BEGIN IMMEDIATE")
            db.execute("INSERT INTO claims VALUES (?, ?, ?, ?, ?, 'CLAIMED')", key())
            db.rollback()
        self.assertEqual(self.claim(key()), "TEST_COMMITTED_NO_DISPATCH")

if __name__ == "__main__":
    unittest.main()
