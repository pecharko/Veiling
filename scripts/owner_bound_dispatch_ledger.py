"""Persistent single-host dispatch ledger; never place on ephemeral runners.

No production DB, migrations, credentials or GitHub access. The approved runtime
must own a pre-provisioned private local filesystem with durable fsync semantics.
There is deliberately no delete, unreserve, retry or takeover operation.
"""
import hashlib
import json
from pathlib import Path
import sqlite3
from contextlib import contextmanager

SCHEMA = """
CREATE TABLE claims (
  nonce TEXT PRIMARY KEY,
  authorization TEXT NOT NULL UNIQUE,
  release_id TEXT NOT NULL UNIQUE,
  binding TEXT NOT NULL,
  state TEXT NOT NULL CHECK(state IN ('RESERVED','ATTEMPTED','ACCEPTED','UNKNOWN','ABORTED')),
  run_id INTEGER
);
CREATE UNIQUE INDEX one_active_dispatch ON claims((1))
  WHERE state != 'ABORTED';
"""


def canonical(binding):
    return json.dumps(binding, sort_keys=True, separators=(',', ':'))


def binding_digest(binding):
    return hashlib.sha256(canonical(binding).encode()).hexdigest()


class Ledger:
    def __init__(self, path):
        self.path = Path(path)
        if self.path.is_symlink() or not self.path.is_file():
            raise ValueError('Require pre-provisioned persistent ledger')

    @classmethod
    def provision(cls, path):
        """Explicit local provisioning only; never called by the dispatcher."""
        path = Path(path)
        # Exclusive create; reopening must never silently replace the ledger.
        with path.open('xb'):
            pass
        path.chmod(0o600)
        ledger = cls(path)
        with ledger._connect() as db:
            db.executescript(SCHEMA)
        return ledger

    @contextmanager
    def _connect(self):
        db = sqlite3.connect(self.path.resolve().as_uri() + '?mode=rw',
                             uri=True, timeout=5, isolation_level=None)
        db.execute('PRAGMA synchronous=FULL')
        try:
            yield db
        finally:
            db.close()

    def reserve(self, binding):
        """Atomic nonce + authorization + release + global active exclusion."""
        with self._connect() as db:
            try:
                db.execute('BEGIN IMMEDIATE')
                db.execute('INSERT INTO claims VALUES (?, ?, ?, ?, ?, NULL)', (
                    binding['nonce'],
                    canonical([binding['repository'], binding['issue_id'], binding['comment_id']]),
                    binding['release_id'], canonical(binding), 'RESERVED'))
                db.execute('COMMIT')
                return True
            except sqlite3.IntegrityError:
                db.execute('ROLLBACK')
                return False
            # Commit exceptions propagate: the caller MUST NOT retry or send.

    def transition(self, binding, old, new):
        allowed = {('RESERVED', 'ATTEMPTED'), ('RESERVED', 'ABORTED'),
                   ('ATTEMPTED', 'ACCEPTED'), ('ATTEMPTED', 'UNKNOWN')}
        if (old, new) not in allowed:
            raise ValueError('Forbidden ledger transition')
        with self._connect() as db:
            db.execute('BEGIN IMMEDIATE')
            changed = db.execute('UPDATE claims SET state=? WHERE nonce=? AND binding=? AND state=?',
                                 (new, binding['nonce'], canonical(binding), old)).rowcount
            if changed != 1:
                raise ValueError('Stale or mismatched claim')
            db.execute('COMMIT')

    def read(self, nonce):
        with self._connect() as db:
            row = db.execute('SELECT binding,state,run_id FROM claims WHERE nonce=?', (nonce,)).fetchone()
        if row is None:
            raise ValueError('Missing claim; never permission to retry')
        return {'binding': json.loads(row[0]), 'state': row[1], 'run_id': row[2]}
