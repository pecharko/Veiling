"""Offline WSGI and raw GitHub signature tests; no listeners or live writes."""
import copy
import hashlib
import hmac
import io
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
sys.path.insert(0, str(Path(__file__).resolve().parent))
import owner_bound_dispatch as guard
import owner_bound_dispatch_service as service
from owner_bound_dispatch_ledger import Ledger
from owner_bound_dispatch_integration_test import api_fixture, API, NONCE
from shared_ovh_controller_adapter_test import NOW

SECRET = b'offline-webhook-signing-key-123456789'


def envelope(api):
    return {
        'action': 'created', 'repository': {
            **copy.deepcopy(api['']), 'id': service.REPOSITORY_ID},
        'issue': copy.deepcopy(api['/issues/512']),
        'comment': copy.deepcopy(api['/issues/comments/77']),
        'sender': copy.deepcopy(api['']['owner']),
    }


def request(event=None, *, raw=None, secret=SECRET):
    if raw is None:
        raw = json.dumps(event, separators=(',', ':')).encode()
    return {
        'REQUEST_METHOD': 'POST', 'PATH_INFO': service.PATH,
        'SCRIPT_NAME': '', 'QUERY_STRING': '', 'wsgi.url_scheme': 'https',
        'CONTENT_TYPE': 'application/json', 'CONTENT_LENGTH': str(len(raw)),
        'HTTP_X_GITHUB_EVENT': 'issue_comment',
        'HTTP_X_GITHUB_DELIVERY': '12345678-abcd-1234-5678-123456789abc',
        'HTTP_X_HUB_SIGNATURE_256': 'sha256=' + hmac.new(secret, raw, hashlib.sha256).hexdigest(),
        'wsgi.input': io.BytesIO(raw),
    }


def response(app, environ):
    start = Mock()
    raw = b''.join(app(environ, start))
    code, headers = start.call_args.args
    headers = dict(headers)
    if headers['Content-Length'] != str(len(raw)):
        raise AssertionError('Wrong WSGI content length')
    return code, json.loads(raw), headers


class ServiceTests(unittest.TestCase):
    def setUp(self):
        self.api = api_fixture()
        self.event = envelope(self.api)
        self.fake = API(self.api)
        self.reader = guard.GitHubEvidence('fake-read-token')
        self.api_patch = patch.object(guard.evidence, 'github_get', side_effect=self.fake)
        self.api_patch.start()
        self.addCleanup(self.api_patch.stop)
        self.app = service.ReviewApplication(SECRET, self.reader, clock=lambda: NOW)

    def test_exported_wsgi_and_cli_never_read_credentials_body_or_open_listener(self):
        environ = request(self.event)
        environ['wsgi.input'] = Mock()
        environ['wsgi.input'].read.side_effect = AssertionError('Body read while disabled')
        with patch.dict('os.environ', {'DISPATCH_ENABLED': 'true', 'SERVICE_ENABLED': 'true'}), \
                patch('socket.socket', side_effect=AssertionError('Listener opened')), \
                patch('builtins.open', side_effect=AssertionError('Credential/file read')):
            code, result, _ = response(service.application, environ)
            with patch('sys.stdout', new_callable=io.StringIO) as output:
                self.assertEqual(service.main(), 78)
        self.assertEqual(code, '503 Service Unavailable')
        self.assertEqual(result['status'], 'DISABLED')
        self.assertEqual(json.loads(output.getvalue())['status'], 'DISABLED')
        self.assertFalse(self.fake.calls)

    def test_signed_request_independent_evidence_redacted_review_only(self):
        with patch.object(guard.GitHubDispatch, 'send_once', side_effect=AssertionError('POST')), \
                patch.object(Ledger, 'reserve', side_effect=AssertionError('Claim write')):
            code, result, headers = response(self.app, request(self.event))
        self.assertEqual(code, '200 OK')
        self.assertEqual(result['status'], 'VERIFIED_READ_ONLY')
        self.assertRegex(result['binding_sha256'], r'^[0-9a-f]{64}$')
        for flag in service.FLAGS:
            self.assertIs(result[flag], False)
        self.assertEqual(headers['Cache-Control'], 'no-store')
        text = json.dumps(result)
        for value in (NONCE, SECRET.decode(), 'fake-read-token', self.event['comment']['body']):
            self.assertNotIn(value, text)
        self.assertIn('/branches/main', self.fake.calls)

    def test_same_body_redelivery_or_changed_delivery_header_never_becomes_write(self):
        env = request(self.event)
        first = response(self.app, env)[1]
        env = request(self.event)
        env['HTTP_X_GITHUB_DELIVERY'] = '87654321-abcd-1234-5678-123456789abc'
        second = response(self.app, env)[1]
        self.assertEqual(first, second)
        self.assertFalse(second['write_allowed'])
        self.assertFalse(second['dispatch_started'])

    def test_bad_signatures_rejected_before_json_or_independent_api(self):
        cases = [
            {'HTTP_X_HUB_SIGNATURE_256': 'sha256=' + '0' * 64},
            {'HTTP_X_HUB_SIGNATURE_256': 'sha1=' + '0' * 40},
            {'HTTP_X_HUB_SIGNATURE_256': 'sha256=' + 'A' * 64},
            {'HTTP_X_HUB_SIGNATURE_256': 'sha256=' + '0' * 64 + ',sha256=' + '1' * 64},
            {'HTTP_X_GITHUB_DELIVERY': 'not-a-delivery'},
            {'HTTP_X_GITHUB_EVENT': 'push'},
        ]
        for fields in cases:
            with self.subTest(fields=fields):
                env = request(self.event)
                env.update(fields)
                self.assertEqual(response(self.app, env)[0], '401 Unauthorized')
                self.assertFalse(self.fake.calls)

    def test_signature_covers_exact_raw_bytes_without_json_reencoding(self):
        compact = json.dumps(self.event, separators=(',', ':')).encode()
        spaced = json.dumps(self.event, indent=2).encode()
        env = request(raw=spaced)
        env['HTTP_X_HUB_SIGNATURE_256'] = request(raw=compact)['HTTP_X_HUB_SIGNATURE_256']
        self.assertEqual(response(self.app, env)[0], '401 Unauthorized')
        self.assertFalse(self.fake.calls)
        self.assertEqual(response(self.app, request(raw=spaced))[0], '200 OK')

    def test_transport_framing_tls_and_size_fail_before_api(self):
        cases = [
            {'REQUEST_METHOD': 'GET'}, {'PATH_INFO': service.PATH + '/other'},
            {'SCRIPT_NAME': '/injected'}, {'QUERY_STRING': 'token=not-accepted'},
            {'wsgi.url_scheme': 'http', 'HTTP_X_FORWARDED_PROTO': 'https'},
            {'CONTENT_TYPE': 'application/json; charset=utf-7'},
            {'HTTP_TRANSFER_ENCODING': 'chunked'}, {'HTTP_CONTENT_ENCODING': 'gzip'},
            {'CONTENT_LENGTH': ''}, {'CONTENT_LENGTH': '01'},
            {'CONTENT_LENGTH': '-1'}, {'CONTENT_LENGTH': '1,2'},
            {'CONTENT_LENGTH': str(service.MAX_BODY + 1)},
            {'CONTENT_LENGTH': '1' * 10000},
            {'wsgi.input': io.BytesIO(b'')},
        ]
        for i, fields in enumerate(cases):
            with self.subTest(case=i):
                env = request(self.event)
                env.update(fields)
                self.assertEqual(response(self.app, env)[0], '400 Bad Request')
                self.assertFalse(self.fake.calls)

    def test_signed_malformed_or_duplicate_json_never_reaches_reader(self):
        raw = json.dumps(self.event).encode()
        cases = [b'null', b'[]', b'{broken', b'\xff',
                 b'{"action":"edited",' + raw[1:],
                 raw.replace(b'"id": 77', b'"id": 78, "id": 77')]
        for data in cases:
            with self.subTest(data=data[:30]):
                self.assertEqual(response(self.app, request(raw=data))[0], '400 Bad Request')
                self.assertFalse(self.fake.calls)

    def test_signed_wrong_owner_repo_or_event_shape_never_reaches_reader(self):
        changes = [
            lambda e: e.update(action='edited'),
            lambda e: e['repository'].update(full_name='other/repository'),
            lambda e: e['repository'].update(id=True),
            lambda e: e['repository'].update(id=10),
            lambda e: e['issue'].update(pull_request={}),
            lambda e: e['issue'].update(state='closed'),
            lambda e: e['issue'].update(number=True),
            lambda e: e['comment'].update(id=True),
            lambda e: e['sender'].update(login='another-owner'),
            lambda e: e['sender'].update(id=456),
            lambda e: e['sender'].update(type='Bot'),
            lambda e: e['issue']['user'].update(id=456),
            lambda e: e['comment']['user'].update(id=456),
            lambda e: e['comment'].update(issue_url='https://untrusted/issues/512'),
            lambda e: e['comment'].update(body=e['comment']['body'].split('\nnonce=')[0]),
            lambda e: e.update(sender=None),
        ]
        for i, change in enumerate(changes):
            with self.subTest(case=i):
                event = copy.deepcopy(self.event)
                change(event)
                self.assertEqual(response(self.app, request(event))[0], '400 Bad Request')
                self.assertFalse(self.fake.calls)

    def test_signed_event_cannot_replace_independent_owner_or_approval_body(self):
        event = copy.deepcopy(self.event)
        event['comment']['body'] += '\n'
        code, result, _ = response(self.app, request(event))
        self.assertEqual(code, '503 Service Unavailable')
        self.assertFalse(result['dispatch_started'])
        # Valid MAC over an internally consistent but forged owner ID remains
        # insufficient: independent GitHub owner ID wins.
        for user in (event['sender'], event['issue']['user'], event['comment']['user'],
                     event['repository']['owner']):
            user['id'] = 456
        event['comment']['body'] = self.event['comment']['body']
        self.assertEqual(response(self.app, request(event))[0], '503 Service Unavailable')

    def test_input_proof_flags_never_grant_dispatch(self):
        event = copy.deepcopy(self.event)
        event.update(dispatch_allowed=True, enabled=True, owner_verified=True)
        _, result, _ = response(self.app, request(event))
        self.assertEqual(result['status'], 'VERIFIED_READ_ONLY')
        self.assertFalse(result['write_allowed'])
        self.assertFalse(result['dispatch_started'])

    def test_independent_api_error_response_is_never_echoed_or_logged(self):
        message = 'fake-read-token private-body-value'
        with patch.object(self.reader, 'prepare', side_effect=RuntimeError(message)), \
                patch('sys.stdout', new_callable=io.StringIO) as out, \
                patch('sys.stderr', new_callable=io.StringIO) as err:
            code, result, _ = response(self.app, request(self.event))
        self.assertEqual(code, '503 Service Unavailable')
        self.assertNotIn(message, json.dumps(result) + out.getvalue() + err.getvalue())
        self.assertFalse(result['retry_allowed'])

    def test_unsigned_delivery_headers_are_not_replay_authority_in_attempt_seam(self):
        event = service.authenticate(request(self.event), SECRET)
        bound = service.BoundReader(self.reader, event)
        sender = Mock()
        with tempfile.TemporaryDirectory() as directory:
            ledger = Ledger.provision(Path(directory) / 'ledger.sqlite')
            first = guard._attempt_once(bound, ledger, sender, 512, 77, clock=lambda: NOW)
            env = request(self.event)
            env['HTTP_X_GITHUB_DELIVERY'] = '87654321-abcd-1234-5678-123456789abc'
            bound = service.BoundReader(self.reader, service.authenticate(env, SECRET))
            second = guard._attempt_once(bound, ledger, sender, 512, 77, clock=lambda: NOW)
            self.assertEqual(first['status'], 'ACCEPTED_RECONCILIATION_REQUIRED')
            self.assertEqual(second['status'], 'REPLAY_OR_ACTIVE_CLAIM')
            self.assertEqual(ledger.read(NONCE)['state'], 'ACCEPTED')
        sender.send_once.assert_called_once()

    def test_bound_reader_refuses_identity_switch_and_later_body_drift(self):
        event = service.authenticate(request(self.event), SECRET)
        bound = service.BoundReader(self.reader, event)
        with self.assertRaises(ValueError):
            bound.prepare(512, 78, NOW)
        self.assertFalse(self.fake.calls)
        binding = bound.prepare(512, 77, NOW)
        with patch.object(self.reader, 'prepare', return_value={**binding, 'approval_sha256': '0' * 64}):
            with self.assertRaises(ValueError):
                bound.prepare(512, 77, NOW)

    def test_weak_secrets_and_arbitrary_reader_not_accepted(self):
        for secret in (b'short', 'not-bytes', b'x' * 1025):
            with self.subTest(secret_type=type(secret).__name__), self.assertRaises(ValueError):
                service.ReviewApplication(secret, self.reader)
        with self.assertRaises(ValueError):
            service.ReviewApplication(SECRET, Mock())


if __name__ == '__main__':
    unittest.main()
