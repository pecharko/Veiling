"""Offline end-to-end security tests: fake GitHub, real temporary durable SQL."""
import copy
from concurrent.futures import ThreadPoolExecutor
from datetime import timedelta
import json
import multiprocessing
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
sys.path.insert(0, str(Path(__file__).resolve().parent))
import owner_bound_dispatch as guard
from owner_bound_dispatch_ledger import Ledger
from shared_ovh_controller_evidence_test import make_api, encode
from shared_ovh_controller_adapter_test import NOW, MAIN_SHA

NONCE = 'e' * 64
CI_QUERY = '/actions/runs?event=push&branch=main&head_sha=' + MAIN_SHA + '&per_page=100'
TARGET_QUERY = '/actions/workflows/shared-ovh-release.yml/runs?per_page=100'


def api_fixture():
    api, _ = make_api()
    owner = {'login': 'pecharko', 'id': 123, 'type': 'User'}
    api[''] = {'full_name': guard.REPO, 'owner': owner}
    api['/issues/512']['user'] = owner.copy()
    comment = api['/issues/comments/77']
    comment['user'] = owner.copy()
    comment['body'] += '\nnonce=' + NONCE
    ci = []
    for i, path in enumerate(sorted(guard.CI_PATHS)):
        ci.append({'id': 800 + i, 'path': path, 'event': 'push',
                   'head_sha': MAIN_SHA, 'head_branch': 'main',
                   'repository': {'full_name': guard.REPO},
                   'head_repository': {'full_name': guard.REPO},
                   'run_attempt': 1, 'status': 'completed', 'conclusion': 'success',
                   'updated_at': (NOW - timedelta(minutes=6)).isoformat()})
    api[CI_QUERY] = {'total_count': len(ci), 'workflow_runs': ci}
    api[TARGET_QUERY] = {'total_count': 0, 'workflow_runs': []}
    for status in ('queued', 'in_progress', 'waiting', 'pending', 'requested'):
        api['/actions/runs?status=' + status + '&per_page=100'] = {
            'total_count': 0, 'workflow_runs': []}
    return api


class API:
    def __init__(self, data):
        self.data = data
        self.calls = []

    def __call__(self, url, token):
        if not url.startswith(guard.evidence.BASE) or token != 'fake-read-token':
            raise AssertionError('Unexpected destination or credential')
        path = url[len(guard.evidence.BASE):]
        self.calls.append(path)
        obj = self.data[path]
        return obj if isinstance(obj, bytes) else encode(obj)


def claim_in_process(path, binding, start, results):
    start.wait(10)
    results.put(Ledger(path).reserve(binding))


class IntegrationTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.path = Path(self.directory.name) / 'claims.sqlite'
        self.ledger = Ledger.provision(self.path)
        self.api = api_fixture()
        self.fake = API(self.api)
        self.reader = guard.GitHubEvidence('fake-read-token')
        self.patch = patch.object(guard.evidence, 'github_get', side_effect=self.fake)
        self.patch.start()
        self.addCleanup(self.patch.stop)
        self.sender = Mock()

    def prepare(self):
        return self.reader.prepare(512, 77, NOW)

    def attempt(self, **kwargs):
        return guard._attempt_once(self.reader, self.ledger, self.sender,
                                   512, 77, clock=lambda: NOW, **kwargs)

    def test_public_entrypoint_cannot_activate_or_touch_dependencies(self):
        with patch.dict('os.environ', {'DISPATCH_ENABLED': 'true'}):
            result = guard.execute(enabled=True, reader=self.reader,
                                   ledger=self.ledger, sender=self.sender)
        self.assertEqual(result['status'], 'DISABLED')
        self.assertFalse(self.fake.calls)
        self.sender.send_once.assert_not_called()

    def test_complete_preparation_claim_attempt_and_independent_candidate(self):
        result = self.attempt()
        self.assertEqual(result['status'], 'ACCEPTED_RECONCILIATION_REQUIRED')
        self.sender.send_once.assert_called_once()
        binding = self.sender.send_once.call_args.args[0]
        self.assertEqual(binding['nonce'], NONCE)
        self.assertEqual(len(binding['files']), 2)
        self.assertEqual(Ledger(self.path).read(NONCE)['state'], 'ACCEPTED')
        self.assertEqual(self.attempt()['status'], 'REPLAY_OR_ACTIVE_CLAIM')
        self.assertEqual(self.sender.send_once.call_count, 1)
        run = copy.deepcopy(self.api[CI_QUERY]['workflow_runs'][0])
        run.update(id=900, path='.github/workflows/shared-ovh-release.yml',
                   event='workflow_dispatch')
        self.api[TARGET_QUERY] = {'total_count': 1, 'workflow_runs': [run]}
        review = guard.reconcile(self.reader, self.ledger, NONCE)
        self.assertEqual(review['candidate_run_ids'], [900])
        for flag in ('dispatch_identity_confirmed', 'retry_allowed',
                     'production_accepted', 'independent_ovh_verified', 'browser_accepted'):
            self.assertIs(review[flag], False)
        self.assertEqual(self.sender.send_once.call_count, 1)
        self.assertEqual(self.ledger.read(NONCE)['state'], 'ACCEPTED')

    def test_each_evidence_gate_blocks_before_claim_or_send(self):
        def mutate_source(api):
            p = '/contents/catalogus-public/admin/admin.css?ref=' + MAIN_SHA
            api[p]['content'] = 'd3Jvbmc='
        changes = [
            lambda a: a['/issues/comments/77']['user'].update(id=456),
            lambda a: a['/issues/512']['user'].update(type='Bot'),
            lambda a: a['/issues/comments/77'].update(updated_at=NOW.isoformat()),
            lambda a: a['/issues/512'].update(state='closed'),
            lambda a: a['/branches/main']['commit'].update(sha='c' * 40),
            lambda a: a[CI_QUERY]['workflow_runs'][0].update(conclusion='failure'),
            lambda a: a[CI_QUERY]['workflow_runs'][0].update(event='pull_request'),
            lambda a: a[CI_QUERY]['workflow_runs'][0].update(head_sha='c' * 40),
            lambda a: a[CI_QUERY]['workflow_runs'][0].update(run_attempt=2),
            lambda a: a[CI_QUERY]['workflow_runs'][0].update(updated_at=NOW.isoformat()),
            lambda a: a[CI_QUERY]['workflow_runs'][0]['head_repository'].update(full_name='fork/repo'),
            lambda a: a[CI_QUERY].update(total_count=100),
            lambda a: a[CI_QUERY].update(workflow_runs=[]),
            lambda a: a[TARGET_QUERY].update(workflow_runs=[{'head_sha': MAIN_SHA}]),
            lambda a: a['/actions/runs?status=waiting&per_page=100'].update(
                workflow_runs=[{'event': 'workflow_dispatch'}]),
            lambda a: a['/issues/512/events?per_page=100'].append({'event': 'closed'}),
            lambda a: a['/issues/comments/77'].update(body=a['/issues/comments/77']['body'].split('\nnonce=')[0]),
            lambda a: a['/issues/comments/77'].update(body=a['/issues/comments/77']['body'].replace(NONCE, 'bad')),
            mutate_source,
        ]
        for i, change in enumerate(changes):
            with self.subTest(gate=i):
                api = api_fixture()
                change(api)
                self.fake.data = api
                self.assertEqual(self.attempt()['status'],
                                 'EVIDENCE_OR_RESERVATION_UNKNOWN_STOP_NO_RETRY')
                self.sender.send_once.assert_not_called()
                with self.assertRaises(ValueError):
                    self.ledger.read(NONCE)

    def test_newer_failed_ci_never_hidden_by_older_success(self):
        old = self.api[CI_QUERY]['workflow_runs'][0]
        new = dict(old, id=999, conclusion='failure')
        self.api[CI_QUERY]['workflow_runs'].append(new)
        self.assertIn('STOP_NO_RETRY', self.attempt()['status'])
        self.sender.send_once.assert_not_called()

    def test_post_reservation_drift_burns_claim(self):
        original = self.reader.prepare
        count = 0
        def prepare(*args):
            nonlocal count
            count += 1
            result = original(*args)
            if count == 2:
                result['manifest_sha256'] = '0' * 64
            return result
        with patch.object(self.reader, 'prepare', side_effect=prepare):
            self.assertEqual(self.attempt()['status'], 'REVALIDATION_STOP_NO_RETRY')
        self.assertEqual(self.ledger.read(NONCE)['state'], 'ABORTED')
        self.assertEqual(self.attempt()['status'], 'REPLAY_OR_ACTIVE_CLAIM')
        self.sender.send_once.assert_not_called()

    def test_transport_timeout_or_explicit_rejection_never_retried(self):
        self.sender.send_once.side_effect = TimeoutError('untrusted response')
        result = self.attempt()
        self.assertEqual(result['status'], 'DISPATCH_UNKNOWN_STOP_NO_RETRY')
        self.assertEqual(Ledger(self.path).read(NONCE)['state'], 'UNKNOWN')
        self.assertEqual(self.attempt()['status'], 'REPLAY_OR_ACTIVE_CLAIM')
        self.assertEqual(self.sender.send_once.call_count, 1)
        self.assertNotIn('untrusted', json.dumps(result))

    def test_reservation_commit_unknown_even_if_persisted_never_sends(self):
        reserve = self.ledger.reserve
        def unknown(binding):
            reserve(binding)
            raise TimeoutError()
        with patch.object(self.ledger, 'reserve', side_effect=unknown):
            self.assertIn('RESERVATION_UNKNOWN', self.attempt()['status'])
        self.assertEqual(self.ledger.read(NONCE)['state'], 'RESERVED')
        self.assertEqual(self.attempt()['status'], 'REPLAY_OR_ACTIVE_CLAIM')
        self.sender.send_once.assert_not_called()

    def test_unknown_attempt_marker_and_post_send_record_failure(self):
        transition = self.ledger.transition
        def fail_after(binding, old, new):
            transition(binding, old, new)
            if new == 'ATTEMPTED':
                raise OSError('ambiguous commit')
        with patch.object(self.ledger, 'transition', side_effect=fail_after):
            self.assertEqual(self.attempt()['status'], 'ATTEMPT_MARKER_UNKNOWN_STOP_NO_RETRY')
        self.sender.send_once.assert_not_called()
        self.assertEqual(self.ledger.read(NONCE)['state'], 'ATTEMPTED')
        self.assertEqual(self.attempt()['status'], 'REPLAY_OR_ACTIVE_CLAIM')

    def test_record_failure_after_accepted_post_stays_nonretryable(self):
        transition = self.ledger.transition
        def fail(binding, old, new):
            if new == 'ACCEPTED':
                raise OSError()
            transition(binding, old, new)
        with patch.object(self.ledger, 'transition', side_effect=fail):
            self.assertEqual(self.attempt()['status'], 'DISPATCH_UNKNOWN_STOP_NO_RETRY')
        self.assertEqual(self.ledger.read(NONCE)['state'], 'ATTEMPTED')
        self.assertEqual(self.attempt()['status'], 'REPLAY_OR_ACTIVE_CLAIM')
        self.assertEqual(self.sender.send_once.call_count, 1)

    def test_parallel_processes_persistent_claim_has_exactly_one_winner(self):
        binding = self.prepare()
        context = multiprocessing.get_context('spawn')
        start, results = context.Event(), context.Queue()
        workers = [context.Process(target=claim_in_process,
                   args=(str(self.path), binding, start, results)) for _ in range(3)]
        for worker in workers:
            worker.start()
        start.set()
        outcomes = [results.get(timeout=20) for _ in workers]
        for worker in workers:
            worker.join(20)
            self.assertEqual(worker.exitcode, 0)
        self.assertEqual(sorted(outcomes), [False, False, True])
        self.assertEqual(Ledger(self.path).read(NONCE)['binding'], binding)

    def test_parallel_end_to_end_attempts_send_once(self):
        with ThreadPoolExecutor(max_workers=3) as executor:
            results = list(executor.map(lambda _: self.attempt(), range(3)))
        self.assertEqual(sum(r['dispatch_started'] for r in results), 1)
        self.sender.send_once.assert_called_once()

    def test_nonce_authorization_release_and_global_active_exclusion(self):
        binding = self.prepare()
        self.assertTrue(self.ledger.reserve(binding))
        for fields in ({'release_id': 'other-release'}, {'nonce': 'f' * 64},
                       {'nonce': 'f' * 64, 'comment_id': 78},
                       {'nonce': 'f' * 64, 'release_id': 'other-release', 'comment_id': 78}):
            with self.subTest(fields=fields):
                self.assertFalse(Ledger(self.path).reserve(dict(binding, **fields)))
        with self.assertRaises(ValueError):
            self.ledger.transition(binding, 'RESERVED', 'ACCEPTED')
        with self.assertRaises(FileExistsError):
            Ledger.provision(self.path)

    def test_reconciliation_no_run_is_not_retry_permission(self):
        self.attempt()
        report = guard.reconcile(self.reader, self.ledger, NONCE)
        self.assertEqual(report['candidate_run_ids'], [])
        self.assertFalse(report['retry_allowed'])
        self.assertEqual(self.ledger.read(NONCE)['state'], 'ACCEPTED')

    def test_consent_changed_between_independent_reads(self):
        original = self.fake.__call__
        count = 0
        def changing(url, token):
            nonlocal count
            result = original(url, token)
            if url.endswith('/issues/comments/77'):
                count += 1
                if count == 3:
                    obj = json.loads(result)
                    obj['body'] += 'edited'
                    return encode(obj)
            return result
        with patch.object(guard.evidence, 'github_get', side_effect=changing):
            self.assertIn('STOP_NO_RETRY', self.attempt()['status'])
        self.sender.send_once.assert_not_called()

    def test_fixed_transport_exact_inputs_and_redirect_denial(self):
        binding = self.prepare()
        response = Mock(status=204)
        url = guard.evidence.BASE + '/actions/workflows/shared-ovh-release.yml/dispatches'
        response.geturl.return_value = url
        response.__enter__ = Mock(return_value=response)
        response.__exit__ = Mock(return_value=False)
        opener = Mock()
        opener.open.return_value = response
        with patch.object(guard, 'build_opener', return_value=opener):
            guard.GitHubDispatch('fake-write-token').send_once(binding)
        request = opener.open.call_args.args[0]
        self.assertEqual(request.full_url, url)
        self.assertEqual(request.get_method(), 'POST')
        self.assertEqual(json.loads(request.data), {'ref': 'main', 'inputs': {
            'release_id': 'shared-demo', 'confirmation': 'DEPLOY-shared-demo-' + MAIN_SHA}})
        opener.open.assert_called_once()
        with self.assertRaises(ValueError):
            guard.NoRedirect().redirect_request(None)


if __name__ == '__main__':
    unittest.main()
