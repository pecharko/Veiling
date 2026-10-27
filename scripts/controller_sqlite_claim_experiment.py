#!/usr/bin/env python3
"""Isolated SQLite concurrency experiment, NEVER production claim storage.

Only accepts caller-provided temporary test database paths. No network, no
credentials, no GitHub dispatch. This demonstrates SQLite uniqueness/locking
behavior, NOT a durable ledger shared between GitHub-hosted runners.
"""
import sqlite3
from controller_one_shot_claim_contract import claim_key

SCHEMA = """
CREATE TABLE claims (
  repo TEXT NOT NULL,
  issue_id INTEGER NOT NULL,
  comment_id INTEGER NOT NULL,
  release_id TEXT NOT NULL,
  release_sha TEXT NOT NULL,
  state TEXT NOT NULL CHECK(state = 'CLAIMED'),
  UNIQUE(repo, issue_id, comment_id),
  UNIQUE(repo, release_id, release_sha)
);
"""


def initialize(connection):
    connection.executescript(SCHEMA)


def try_claim(connection, key):
    """Single transaction; unknown exceptions must not be retried as dispatch."""
    repo, issue, comment, release, sha = claim_key(*key)
    try:
        connection.execute("BEGIN IMMEDIATE")
        connection.execute(
            "INSERT INTO claims(repo, issue_id, comment_id, release_id, release_sha, state) "
            "VALUES (?, ?, ?, ?, ?, 'CLAIMED')",
            (repo, issue, comment, release, sha),
        )
        connection.commit()
        return "TEST_COMMITTED_NO_DISPATCH"
    except sqlite3.IntegrityError:
        connection.rollback()
        return "UNIQUE_CONFLICT"
    except BaseException:
        connection.rollback()
        raise
