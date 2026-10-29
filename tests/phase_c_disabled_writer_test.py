"""Adversarial tests for the inert Phase C writer contract."""
import unittest
from dataclasses import replace

from scripts.phase_c_disabled_writer import (
    Transaction, WRITER_ENABLED, execute_transaction, prepare_transaction,
)


class HostileTransport:
    def __init__(self):
        self.calls = []

    def __getattr__(self, name):
        def forbidden(*args, **kwargs):
            self.calls.append((name, args, kwargs))
            raise AssertionError("transport must never be called")
        return forbidden


class DisabledWriterTests(unittest.TestCase):
    def test_static_lock_and_scoped_preparation(self):
        self.assertIs(WRITER_ENABLED, False)
        prepared = prepare_transaction(Transaction())
        self.assertEqual(prepared["decision"], "PREPARED_INACTIVE")
        self.assertFalse(prepared["write_allowed"])

    def test_scope_drift_denied(self):
        changes = [
            {"repository": "other/repo"}, {"branch": "main"},
            {"head": "0" * 40}, {"blob": "0" * 40},
            {"path": "catalogus-public/index.php"},
            {"nonce": "refs/tags/phase-c-once-issue-428"},
            {"issue": 428},
        ]
        for fields in changes:
            with self.subTest(fields=fields):
                result = prepare_transaction(replace(Transaction(), **fields))
                self.assertEqual(result["decision"], "DENY")
                self.assertFalse(result["write_allowed"])

    def test_no_transport_calls_for_any_arguments(self):
        for kwargs in (
            {}, {"enabled": True}, {"authorized": True},
            {"nonce_create": "ok", "cas": "ok"},
            {"transaction": Transaction()},
            {"transaction": replace(Transaction(), branch="main")},
        ):
            with self.subTest(kwargs=kwargs):
                fake = HostileTransport()
                result = execute_transaction(transport=fake, **kwargs)
                self.assertEqual(result["decision"], "DENY")
                self.assertFalse(result["write_allowed"])
                self.assertFalse(result["retry_allowed"])
                self.assertEqual(result["operations"], [])
                self.assertEqual(fake.calls, [])

    def test_no_transport_even_if_object_has_write_methods(self):
        class Evil:
            def __getattribute__(self, key):
                raise AssertionError("writer attempted to inspect a credential or client")
        self.assertEqual(execute_transaction(Evil())["decision"], "DENY")


if __name__ == "__main__":
    unittest.main()
