"""OVH proposal tests: independent authority, no-retry and Python/PHP parity."""
from concurrent.futures import ThreadPoolExecutor
import copy
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import threading
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
sys.path.insert(0, str(ROOT / 'tests'))
import ovh_controller_claim_adapter as adapter
import owner_bound_dispatch as guard
from owner_bound_dispatch_ledger import canonical, binding_digest
from owner_bound_dispatch_integration_test import api_fixture, API, NONCE, CI_QUERY
from shared_ovh_controller_adapter_test import NOW

KEY = b'offline-ovh-claims-signing-key-123456789'
TIME = int(NOW.timestamp())


def ovh_api():
    api = api_fixture()
    mysql = {**copy.deepcopy(api[CI_QUERY]['workflow_runs'][0]), 'id': 900, 'path': adapter.MYSQL_CI}
    api[CI_QUERY]['workflow_runs'].append(mysql)
    api[CI_QUERY]['total_count'] = 3
    return api


def binding_fixture():
    with patch.object(guard.evidence, 'github_get', side_effect=API(ovh_api())):
        return adapter.OvhEvidence('fake-read-token').prepare(512, 77, NOW)


class TestAudit:
    """Test double, NOT a production durable authority or restore proof."""
    def __init__(self):
        self.records = {}
        self.lock = threading.Lock()

    def reserve_once(self, binding):
        with self.lock:
            # Conservative global one-shot lock, also guards nonce/consent/release.
            if self.records:
                return None
            self.records[binding['nonce']] = copy.deepcopy(binding)
            return adapter.AuditReservation(binding['nonce'], binding_digest(binding))

    def lookup(self, nonce):
        return copy.deepcopy(self.records.get(nonce))


class SqlModel:
    """Rollback simulation only; real MySQL coverage runs separately in CI."""
    def __init__(self):
        self.rows = {}
        self.calls = []

    def reserve(self, binding):
        self.calls.append('reserve')
        if self.rows:
            return False
        self.rows[binding['nonce']] = {'binding': copy.deepcopy(binding), 'state': 'RESERVED'}
        return True

    def transition(self, binding, old, new):
        self.calls.append('transition')
        row = self.rows[binding['nonce']]
        if row['binding'] != binding or row['state'] != old or (old, new) not in adapter.TRANSITIONS:
            raise ValueError('Invalid SQL transition')
        row['state'] = new

    def read_bound(self, binding):
        self.calls.append('read')
        row = self.rows[binding['nonce']]
        if row['binding'] != binding:
            raise ValueError('Binding drift')
        return {**copy.deepcopy(row), 'run_id': None}


class SignedTransport:
    def __init__(self, mutate=None):
        self.calls = []
        self.mutate = mutate

    def exchange(self, raw, signature):
        self.calls.append((raw, signature))
        if signature != adapter.mac(KEY, adapter.REQUEST_DOMAIN, raw):
            raise AssertionError('Request not authenticated')
        r = json.loads(raw)
        response = {'schema': 1, 'operation': r['operation'], 'request_nonce': r['request_nonce'],
                    'binding_sha256': binding_digest(r['binding']), 'outcome': 'RESERVED', 'record': None}
        if self.mutate:
            self.mutate(response)
        body = canonical(response).encode()
        return body, adapter.mac(KEY, adapter.RESPONSE_DOMAIN, body)


class AdapterTests(unittest.TestCase):
    def setUp(self):
        self.binding = binding_fixture()

    def test_public_execute_disabled_even_with_all_caller_proof_flags(self):
        io = Mock()
        result = adapter.execute(enabled=True, owner_verified=True, independent_audit=True,
                                 transport=io, audit=io)
        self.assertEqual(result['status'], 'DISABLED')
        self.assertFalse(result['dispatch_started'])
        self.assertFalse(io.mock_calls)

    def test_ovh_binding_requires_latest_independent_mysql_push_ci_before_owner_consent(self):
        self.assertEqual(self.binding['push_ci_run_ids'], [800, 801, 900])
        for change in ({'conclusion': 'failure'}, {'event': 'pull_request'},
                       {'run_attempt': 2}, {'status': 'in_progress'},
                       {'updated_at': NOW.isoformat()}, {'head_sha': 'f' * 40}):
            api = ovh_api()
            api[CI_QUERY]['workflow_runs'][-1].update(change)
            with self.subTest(change=change), patch.object(guard.evidence, 'github_get', side_effect=API(api)):
                with self.assertRaises(ValueError):
                    adapter.OvhEvidence('fake-read-token').prepare(512, 77, NOW)
        with patch.object(guard.evidence, 'github_get', side_effect=API(api_fixture())):
            with self.assertRaises(ValueError):
                adapter.OvhEvidence('fake-read-token').prepare(512, 77, NOW)

    def test_independent_reservation_precedes_remote_and_every_attempt_gate(self):
        audit, remote, sender = TestAudit(), SqlModel(), Mock()
        ledger = adapter.RestoreSafeLedger(remote, audit)
        reader = Mock()
        reader.prepare.side_effect = lambda *_: copy.deepcopy(self.binding)
        remote_reserve = remote.reserve
        def verify_order(binding):
            self.assertEqual(audit.lookup(binding['nonce']), binding)
            return remote_reserve(binding)
        with patch.object(remote, 'reserve', side_effect=verify_order):
            result = guard._attempt_once(reader, ledger, sender, 512, 77, clock=lambda: NOW)
        self.assertEqual(result['status'], 'ACCEPTED_RECONCILIATION_REQUIRED')
        self.assertEqual(ledger.read(NONCE)['state'], 'ACCEPTED')
        sender.send_once.assert_called_once()

    def test_sql_restore_cannot_reissue_old_approval_or_new_comment_for_release(self):
        audit, remote = TestAudit(), SqlModel()
        ledger = adapter.RestoreSafeLedger(remote, audit)
        self.assertTrue(ledger.reserve(self.binding))
        remote.rows = {}  # Simulate the OVH snapshot predating the claim.
        self.assertFalse(ledger.reserve(self.binding))
        changed = {**self.binding, 'comment_id': 78, 'nonce': 'a' * 64}
        self.assertFalse(ledger.reserve(changed))
        self.assertEqual(remote.calls, ['reserve'])
        with self.assertRaises(KeyError):
            ledger.read(NONCE)
        self.assertEqual(audit.lookup(NONCE), self.binding)

    def test_missing_unavailable_or_json_audit_proof_never_touches_sql(self):
        remote = Mock()
        for audit in (None, Mock(reserve_once=Mock(side_effect=TimeoutError())),
                      Mock(reserve_once=Mock(return_value=True)),
                      Mock(reserve_once=Mock(return_value={'durable': True})),
                      Mock(reserve_once=Mock(return_value=adapter.AuditReservation(NONCE, '0' * 64)))):
            with self.subTest(audit_type=type(audit).__name__), self.assertRaises(Exception):
                adapter.RestoreSafeLedger(remote, audit).reserve(self.binding)
        self.assertFalse(remote.mock_calls)

    def test_unknown_audit_commit_persists_and_blocks_retry_without_sql(self):
        audit, remote = TestAudit(), Mock()
        reserve = audit.reserve_once
        def unknown(binding):
            reserve(binding)
            raise TimeoutError()
        ledger = adapter.RestoreSafeLedger(remote, audit)
        with patch.object(audit, 'reserve_once', side_effect=unknown), self.assertRaises(TimeoutError):
            ledger.reserve(self.binding)
        self.assertFalse(ledger.reserve(self.binding))
        self.assertFalse(remote.mock_calls)

    def test_unknown_remote_commit_burns_external_claim_and_prevents_second_exchange(self):
        audit = TestAudit()
        transport = Mock(exchange=Mock(side_effect=TimeoutError('private-error')))
        ledger = adapter.RestoreSafeLedger(adapter.RemoteLedger(transport, KEY, clock=lambda: TIME), audit)
        with self.assertRaisesRegex(RuntimeError, 'STOP, NO RETRY') as failure:
            ledger.reserve(self.binding)
        self.assertNotIn('private-error', str(failure.exception))
        self.assertFalse(ledger.reserve(self.binding))
        self.assertEqual(transport.exchange.call_count, 1)

    def test_independent_authority_disappearing_blocks_attempt_marker(self):
        audit, remote = TestAudit(), SqlModel()
        ledger = adapter.RestoreSafeLedger(remote, audit)
        ledger.reserve(self.binding)
        audit.records = {}  # Must never be treated as proof that it was unused.
        with self.assertRaises(RuntimeError):
            ledger.transition(self.binding, 'RESERVED', 'ATTEMPTED')
        self.assertEqual(remote.rows[NONCE]['state'], 'RESERVED')

    def test_parallel_independent_claims_have_one_remote_winner(self):
        audit, remote = TestAudit(), SqlModel()
        ledger = adapter.RestoreSafeLedger(remote, audit)
        with ThreadPoolExecutor(max_workers=4) as workers:
            results = list(workers.map(lambda _: ledger.reserve(self.binding), range(4)))
        self.assertEqual(sorted(results), [False, False, False, True])
        self.assertEqual(remote.calls, ['reserve'])

    def test_signed_response_must_bind_operation_request_nonce_and_exact_digest(self):
        mutations = [lambda r: r.update(operation='read'), lambda r: r.update(request_nonce='0' * 64),
                     lambda r: r.update(binding_sha256='0' * 64), lambda r: r.update(schema=True),
                     lambda r: r.update(extra=True), lambda r: r.update(outcome='UNKNOWN_STOP_NO_RETRY'),
                     lambda r: r.update(record={'state': 'RESERVED'})]
        for mutate in mutations:
            transport = SignedTransport(mutate)
            with self.subTest(mutate=mutate), self.assertRaisesRegex(RuntimeError, 'NO RETRY'):
                adapter.RemoteLedger(transport, KEY, clock=lambda: TIME).reserve(self.binding)
            self.assertEqual(len(transport.calls), 1)

    def test_response_tamper_or_request_reflection_cannot_authorize_sql_success(self):
        transport = SignedTransport()
        original = transport.exchange
        def tamper(raw, signature):
            body, sig = original(raw, signature)
            return body + b' ', sig
        with patch.object(transport, 'exchange', side_effect=tamper), self.assertRaises(RuntimeError):
            adapter.RemoteLedger(transport, KEY, clock=lambda: TIME).reserve(self.binding)
        reflection = Mock(exchange=Mock(side_effect=lambda raw, sig: (raw, sig)))
        with self.assertRaises(RuntimeError):
            adapter.RemoteLedger(reflection, KEY, clock=lambda: TIME).reserve(self.binding)

    def test_invalid_bindings_fail_before_transport(self):
        cases = [{'owner_id': True}, {'issue_id': 2**54}, {'nonce': 'bad'},
                 {'push_ci_run_ids': [1, 1]}, {'push_ci_run_ids': [1, True]},
                 {'files': [{'path': 'private/config.php', 'source_sha256': 'a' * 64, 'remote_sha256': 'ABSENT'}]},
                 {'repository': 'other/repo'}, {'unexpected_proof': True}]
        transport = Mock()
        for changed in cases:
            with self.subTest(changed=changed), self.assertRaises(ValueError):
                adapter.RemoteLedger(transport, KEY).reserve({**self.binding, **changed})
        self.assertFalse(transport.mock_calls)


class PHPTransport:
    def __init__(self, mode='normal'):
        self.mode = mode
        self.calls = 0

    def exchange(self, raw, signature):
        self.calls += 1
        case = {'body': raw.decode(), 'signature': signature, 'now': TIME, 'mode': self.mode}
        response = php_cases([case])[0]
        if not response['accepted']:
            raise ValueError('PHP rejected request')
        r = response['response']
        return r['body'].encode(), r['signature']


def php_cases(cases):
    result = subprocess.run([shutil.which('php'), str(ROOT / 'tests/ovh_controller_claim_protocol_test.php')],
                            input=json.dumps({'key': KEY.decode(), 'cases': cases}).encode(),
                            capture_output=True, timeout=20, check=True)
    return json.loads(result.stdout)


class CrossLanguageTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not shutil.which('php'):
            if os.environ.get('OVH_CLAIM_REQUIRE_PHP') == '1':
                raise AssertionError('Required real PHP protocol test runner missing')
            raise unittest.SkipTest('PHP absent locally; mandatory cross-language CI runs with PHP')

    def setUp(self):
        self.binding = binding_fixture()

    def test_real_php_verifies_python_mac_canonical_binding_and_signs_reply(self):
        transport = PHPTransport()
        ledger = adapter.RemoteLedger(transport, KEY, clock=lambda: TIME)
        self.assertTrue(ledger.reserve(self.binding))
        ledger.transition(self.binding, 'RESERVED', 'ATTEMPTED')
        self.assertEqual(ledger.read_bound(self.binding)['binding'], self.binding)
        self.assertEqual(transport.calls, 3)

    def test_real_php_unknown_after_store_exception_is_not_retried_or_leaked(self):
        transport = PHPTransport('throw')
        with self.assertRaisesRegex(RuntimeError, 'NO RETRY') as failure:
            adapter.RemoteLedger(transport, KEY, clock=lambda: TIME).reserve(self.binding)
        self.assertNotIn('synthetic-private-error', str(failure.exception))
        self.assertEqual(transport.calls, 1)

    def test_real_php_auth_schema_path_time_and_duplicate_checks_precede_store(self):
        transport = SignedTransport()
        adapter.RemoteLedger(transport, KEY, clock=lambda: TIME).reserve(self.binding)
        raw, signature = transport.calls[0]
        original = json.loads(raw)
        cases = []
        for changed in ({'schema': True}, {'operation': 'delete'}, {'expires_at': TIME + 301},
                        {'issued_at': TIME + 31, 'expires_at': TIME + 300},
                        {'expires_at': TIME - 1, 'issued_at': TIME - 100},
                        {'request_nonce': 'bad'}, {'from_state': 'RESERVED'}, {'caller_proof': True}):
            b = canonical({**original, **changed}).encode()
            cases.append({'body': b.decode(), 'signature': adapter.mac(KEY, adapter.REQUEST_DOMAIN, b), 'now': TIME})
        for binding_change in ({'owner_id': True}, {'owner_id': 2**54}, {'push_ci_run_ids': [1, 1]},
                               {'files': [{'path': 'private/config.php', 'source_sha256': 'a' * 64,
                                           'remote_sha256': 'ABSENT'}]}):
            b = canonical({**original, 'binding': {**self.binding, **binding_change}}).encode()
            cases.append({'body': b.decode(), 'signature': adapter.mac(KEY, adapter.REQUEST_DOMAIN, b), 'now': TIME})
        duplicate = raw[:-1] + b',"schema":1}'
        cases.append({'body': duplicate.decode(), 'signature': adapter.mac(KEY, adapter.REQUEST_DOMAIN, duplicate), 'now': TIME})
        cases.append({'body': raw.decode(), 'signature': '0' * 64, 'now': TIME})
        for result in php_cases(cases):
            self.assertFalse(result['accepted'])
            self.assertEqual(result['factory_calls'], 0)

    def test_php_direct_execution_always_disabled(self):
        result = subprocess.run([shutil.which('php'), str(ROOT / 'scripts/ovh_controller_claim_store.php')],
                                capture_output=True, timeout=10)
        self.assertEqual(result.returncode, 78)
        self.assertEqual(json.loads(result.stdout)['status'], 'DISABLED')


if __name__ == '__main__':
    unittest.main()
