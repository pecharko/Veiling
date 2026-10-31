"""Local offline atomic nonce-store prototype. Not a GitHub Actions durable store.

This module makes no GitHub calls and grants no write permissions. A future
trusted executor needs an independently approved durable GitHub-native store.
"""
import sqlite3


class OfflineNonceStore:
    def __init__(self, db_path):
        self.db_path = str(db_path)
        with sqlite3.connect(self.db_path, timeout=5) as db:
            db.execute("CREATE TABLE IF NOT EXISTS spent (issue_id INTEGER NOT NULL, comment_id INTEGER NOT NULL, PRIMARY KEY(issue_id, comment_id))")

    def reserve_once(self, issue_id, comment_id):
        if any(type(value) is not int or value <= 0 for value in (issue_id, comment_id)):
            return False
        with sqlite3.connect(self.db_path, timeout=5, isolation_level=None) as db:
            try:
                db.execute("BEGIN IMMEDIATE")
                db.execute("INSERT INTO spent(issue_id, comment_id) VALUES (?, ?)", (issue_id, comment_id))
                db.execute("COMMIT")
                return True
            except sqlite3.IntegrityError:
                db.execute("ROLLBACK")
                return False
            except Exception:
                db.execute("ROLLBACK")
                raise
