"""Independent audit proposal tests; fake GitHub CAS, never external writes."""
import base64
from concurrent.futures import ThreadPoolExecutor
import copy
import hashlib
import json
from pathlib import Path
import sys
import subprocess
import tempfile
import threading
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'scripts'))
sys.path.insert(0, str(ROOT/'tests'))
import ovh_controller_audit as audit
import ovh_controller_claim_adapter as adapter
import owner_bound_dispatch as guard
from owner_bound_dispatch_ledger import Ledger, canonical, binding_digest
from ovh_controller_claim_adapter_test import binding_fixture, SqlModel
from shared_ovh_controller_adapter_test import NOW

ANCHOR = 'a' * 40
POLICY_TIME = '2026-11-01T00:00:00Z'


def config():
    return audit.AuditConfig(repository_id=9001, anchor_sha=ANCHOR,
                             ruleset_id=41, policy_updated_at=POLICY_TIME)


class FakeAuditAPI:
    """Atomic test backend, not evidence of live GitHub account permissions."""
    def __init__(self, binding):
        self.repo = {'id':9001, 'full_name':audit.REPOSITORY, 'private':True,
                     'fork':False, 'archived':False, 'disabled':False, 'default_branch':'main',
                     'owner':{'login':'pecharko','type':'User','id':binding['owner_id']},
                     'permissions':{'pull':True,'push':True,'admin':False,'maintain':False}}
        proposal = json.loads((ROOT/'deploy/controller/audit-main-ruleset.proposal.json').read_text())
        self.policy = {**proposal, 'id':41, 'source_type':'Repository',
                       'source':audit.REPOSITORY, 'updated_at':POLICY_TIME}
        self.commits, self.trees, self.blobs = {}, {}, {}
        self.install(ANCHOR, [], audit.BOOTSTRAP)
        self.head = ANCHOR
        self.calls, self.writes = [], []
        self.lock = threading.Lock()
        self.barrier = None
        self.head_reads = 0
        self.mode = None

    def install(self, sha, parents, raw):
        blob = hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest()
        tree = hashlib.sha1(b'synthetic-tree-'+raw).hexdigest()
        self.blobs[blob] = {'sha':blob,'size':len(raw),'encoding':'base64',
                            'content':base64.b64encode(raw).decode()}
        self.trees[tree] = {'sha':tree,'truncated':False,'tree':[
            {'path':'claims.json','mode':'100644','type':'blob','sha':blob}]}
        self.commits[sha] = {'sha':sha,'tree':{'sha':tree},
                             'parents':[{'sha':p} for p in parents]}

    def get(self, path):
        with self.lock:
            self.calls.append(path)
            if path == '': value = self.repo
            elif path == '/rulesets/41': value = self.policy
            elif path == '/git/ref/heads/main':
                value = {'ref':'refs/heads/main','object':{'type':'commit','sha':self.head}}
            elif path.startswith('/git/commits/'): value = self.commits[path.rsplit('/',1)[1]]
            elif path.startswith('/git/trees/'): value = self.trees[path.rsplit('/',1)[1]]
            elif path.startswith('/git/blobs/'): value = self.blobs[path.rsplit('/',1)[1]]
            else: raise AssertionError('Unexpected audit endpoint')
            result = copy.deepcopy(value)
            wait = path == '/git/ref/heads/main' and self.barrier and self.head_reads < self.barrier.parties
            if path == '/git/ref/heads/main': self.head_reads += 1
        if wait:
            self.barrier.wait(timeout=10)
        return result

    def commit_once(self, expected_head, raw):
        with self.lock:
            self.writes.append((expected_head, raw))
            if self.mode == 'timeout_without_commit':
                raise TimeoutError('synthetic-private-token')
            if self.head != expected_head:
                return {'data':{'createCommitOnBranch':None}, 'errors':[{'type':'STALE_DATA'}]}
            sha = hashlib.sha1(b'synthetic-commit-'+expected_head.encode()+raw).hexdigest()
            self.install(sha, [expected_head], raw)
            self.head = sha
            if self.mode == 'commit_then_timeout':
                raise TimeoutError('synthetic-private-token')
            if self.mode == 'changed_policy':
                self.policy['updated_at'] = '2026-11-01T00:01:00Z'
            if self.mode == 'wrong_ack': sha = 'f' * 40
            return {'data':{'createCommitOnBranch':{'commit':{'oid':sha}}}}


class AuditTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.journal_count = 0
        self.binding = binding_fixture()
        self.api = FakeAuditAPI(self.binding)
        self.audit = self.provider(self.api, config())

    def provider(self, api, cfg):
        self.journal_count += 1
        journal = Ledger.provision(Path(self.directory.name)/f'attempt-{self.journal_count}.sqlite')
        return audit.GitHubAudit(api, cfg, journal)

    def test_default_execute_no_io_and_bootstrap_packet_exact(self):
        dependency = Mock()
        self.assertEqual(audit.execute(enabled=True, api=dependency, config=config())['status'], 'DISABLED')
        self.assertFalse(dependency.mock_calls)
        self.assertEqual((ROOT/'deploy/controller/audit-claims.bootstrap.json').read_bytes(), audit.BOOTSTRAP)

    def test_exact_cas_receipt_then_immutable_lookup_and_replay_refusal(self):
        receipt = self.audit.reserve_once(self.binding)
        self.assertEqual(receipt, adapter.AuditReservation(self.binding['nonce'], binding_digest(self.binding)))
        self.assertEqual(self.api.writes[0][0], ANCHOR)
        self.assertEqual(json.loads(self.api.writes[0][1]), {'schema':1,'claims':[self.binding]})
        self.assertEqual(self.audit.lookup(self.binding['nonce']), self.binding)
        self.assertIsNone(self.audit.reserve_once(self.binding))
        changed = {**self.binding, 'nonce':'f'*64, 'comment_id':78, 'release_id':'another-release'}
        self.assertIsNone(self.provider(self.api, config()).reserve_once(changed))
        self.assertEqual(len(self.api.writes), 1)

    def test_parallel_clients_same_head_only_one_can_issue_fresh_receipt(self):
        self.api.barrier = threading.Barrier(2)
        def reserve(_):
            try: return self.provider(self.api, config()).reserve_once(copy.deepcopy(self.binding))
            except RuntimeError: return None
        with ThreadPoolExecutor(max_workers=2) as pool:
            outcomes = list(pool.map(reserve, range(2)))
        self.assertEqual(sum(type(r) is adapter.AuditReservation for r in outcomes), 1)
        self.assertEqual(len(self.api.writes), 2)
        self.assertEqual({head for head,_ in self.api.writes}, {ANCHOR})

    def test_real_controller_seam_with_independent_provider_then_sql_restore(self):
        sql, sender, reader = SqlModel(), Mock(), Mock()
        reader.prepare.side_effect = lambda *_: copy.deepcopy(self.binding)
        ledger = adapter.RestoreSafeLedger(sql, self.audit)
        first = guard._attempt_once(reader, ledger, sender, 512, 77, clock=lambda: NOW)
        self.assertEqual(first['status'], 'ACCEPTED_RECONCILIATION_REQUIRED')
        self.assertEqual(ledger.read(self.binding['nonce'])['state'], 'ACCEPTED')
        sql.rows = {}  # Roll OVH state back, while independent GitHub history persists.
        reopened = adapter.RestoreSafeLedger(sql, self.provider(self.api, config()))
        second = guard._attempt_once(reader, reopened, sender, 512, 77, clock=lambda: NOW)
        self.assertEqual(second['status'], 'REPLAY_OR_ACTIVE_CLAIM')
        sender.send_once.assert_called_once()
        self.assertEqual(len(self.api.writes), 1)

    def test_repo_identity_privacy_roles_and_owner_drift_block_before_post(self):
        changes = [lambda r:r.update(id=9002), lambda r:r.update(private=False),
                   lambda r:r.update(full_name='pecharko/Veiling'), lambda r:r.update(fork=True),
                   lambda r:r.update(archived=True), lambda r:r.update(disabled=True),
                   lambda r:r.update(default_branch='other'), lambda r:r['owner'].update(id=456),
                   lambda r:r['owner'].update(login='other'), lambda r:r['owner'].update(type='Bot'),
                   lambda r:r['permissions'].update(admin=True), lambda r:r['permissions'].update(maintain=True),
                   lambda r:r['permissions'].update(push=False), lambda r:r['permissions'].update(pull=False),
                   lambda r:r.pop('permissions')]
        for i, change in enumerate(changes):
            api = FakeAuditAPI(self.binding); change(api.repo)
            with self.subTest(case=i), self.assertRaises(RuntimeError):
                self.provider(api, config()).reserve_once(self.binding)
            self.assertFalse(api.writes)

    def test_policy_baseline_effective_scope_bypass_and_required_rules_are_mandatory(self):
        changes = [lambda p:p.update(id=42), lambda p:p.update(source='pecharko/Veiling'),
                   lambda p:p.update(enforcement='evaluate'), lambda p:p.update(target='tag'),
                   lambda p:p.update(updated_at='2026-11-01T00:00:01Z'),
                   lambda p:p.update(bypass_actors=[{'actor_id':1}]),
                   lambda p:p['conditions']['ref_name'].update(include=['refs/heads/other']),
                   lambda p:p['conditions']['ref_name'].update(exclude=['refs/heads/main']),
                   lambda p:p.update(rules=p['rules'][:2]),
                   lambda p:p.update(rules=[{'type':'deletion'}]*3),
                   lambda p:p['rules'][0].update(parameters={})]
        for i, change in enumerate(changes):
            api=FakeAuditAPI(self.binding);change(api.policy)
            with self.subTest(case=i), self.assertRaises(RuntimeError):
                self.provider(api, config()).reserve_once(self.binding)
            self.assertFalse(api.writes)

    def test_missing_wrong_shape_symlink_or_truncated_bootstrap_never_means_unused(self):
        for kind in ('nonroot','symlink','truncated','missing','duplicate','extra-file','corrupt-blob'):
            api=FakeAuditAPI(self.binding)
            c=api.commits[ANCHOR];t=api.trees[c['tree']['sha']]
            if kind=='nonroot': c['parents']=[{'sha':'f'*40}]
            elif kind=='symlink': t['tree'][0]['mode']='120000'
            elif kind=='truncated': t['truncated']=True
            elif kind=='missing': t['tree'][0]['path']='other.json'
            elif kind=='duplicate': t['tree'].append(copy.deepcopy(t['tree'][0]))
            elif kind=='extra-file': t['tree'].append({**t['tree'][0], 'path':'unexpected.json'})
            else: api.blobs[t['tree'][0]['sha']]['content']=base64.b64encode(b'changed').decode()
            with self.subTest(kind=kind), self.assertRaises(RuntimeError):
                self.provider(api, config()).reserve_once(self.binding)
            self.assertFalse(api.writes)

    def test_append_that_clears_claim_or_adds_history_blocks_no_old_good_fallback(self):
        self.audit.reserve_once(self.binding)
        previous=self.api.head
        sha='e'*40
        self.api.install(sha,[previous],audit.BOOTSTRAP)
        self.api.head=sha
        with self.assertRaises(RuntimeError): self.audit.reserve_once(self.binding)
        with self.assertRaises(RuntimeError): self.audit.lookup(self.binding['nonce'])
        self.assertEqual(len(self.api.writes),1)

    def test_unknown_commit_result_poisons_writer_and_readback_never_remints_receipt(self):
        self.api.mode='commit_then_timeout'
        with self.assertRaisesRegex(RuntimeError,'NO RETRY') as failure:
            self.audit.reserve_once(self.binding)
        self.assertNotIn('synthetic-private-token',str(failure.exception))
        self.assertEqual(self.audit.lookup(self.binding['nonce']),self.binding)
        with self.assertRaises(RuntimeError): self.audit.reserve_once(self.binding)
        self.assertIsNone(self.provider(self.api,config()).reserve_once(self.binding))
        self.assertEqual(len(self.api.writes),1)

    def test_absence_after_uncertain_write_does_not_clear_writer_stop(self):
        self.api.mode='timeout_without_commit'
        with self.assertRaises(RuntimeError): self.audit.reserve_once(self.binding)
        with self.assertRaises(RuntimeError): self.audit.lookup(self.binding['nonce'])
        self.api.mode=None
        with self.assertRaises(RuntimeError): self.audit.reserve_once(self.binding)
        self.assertEqual(len(self.api.writes),1)

    def test_policy_drift_or_mismatched_ack_after_commit_never_issues_receipt(self):
        for mode in ('changed_policy','wrong_ack'):
            api=FakeAuditAPI(self.binding);api.mode=mode
            with self.subTest(mode=mode), self.assertRaises(RuntimeError):
                self.provider(api,config()).reserve_once(self.binding)
            self.assertEqual(len(api.writes),1)

    def test_lookup_requires_exact_nonce_and_cannot_confirm_unrelated_claim(self):
        with self.assertRaises(RuntimeError): self.audit.lookup(self.binding['nonce'])
        self.audit.reserve_once(self.binding)
        with self.assertRaises(RuntimeError): self.audit.lookup('0'*64)
        with self.assertRaises(ValueError): self.audit.lookup('invalid')

    def test_missing_or_fake_journal_cannot_reach_network(self):
        api=Mock()
        provider=audit.GitHubAudit(api,config())
        with self.assertRaisesRegex(RuntimeError,'journal missing'):
            provider.reserve_once(self.binding)
        self.assertFalse(api.mock_calls)
        with self.assertRaises(ValueError): audit.GitHubAudit(api,config(),Mock())

    def test_consumed_journal_binds_exact_audit_config_and_survives_empty_remote(self):
        self.audit.reserve_once(self.binding)
        journal=self.audit.attempt_journal
        self.assertEqual(audit.GitHubAudit(self.api,config()).lookup(self.binding['nonce']),self.binding)
        stored=journal.read(self.binding['nonce'])
        self.assertEqual(stored['state'],'ATTEMPTED')
        self.assertEqual(stored['binding'],{**self.binding,'audit_attempt':{
            'repository':audit.REPOSITORY,'repository_id':9001,'anchor_sha':ANCHOR,
            'ruleset_id':41,'policy_updated_at':POLICY_TIME}})
        # Independent restore/empty readback cannot release the local global lock.
        empty=FakeAuditAPI(self.binding)
        reopened=audit.GitHubAudit(empty,config(),Ledger(journal.path))
        changed={**self.binding,'nonce':'e'*64,'release_id':'later','comment_id':79}
        self.assertIsNone(reopened.reserve_once(changed))
        self.assertFalse(empty.writes)

    def test_timeout_without_remote_commit_remains_blocked_after_reopen(self):
        self.api.mode='timeout_without_commit'
        with self.assertRaises(RuntimeError): self.audit.reserve_once(self.binding)
        reopened=audit.GitHubAudit(self.api,config(),Ledger(self.audit.attempt_journal.path))
        self.api.mode=None
        with self.assertRaises(RuntimeError): reopened.lookup(self.binding['nonce'])
        self.assertIsNone(reopened.reserve_once(self.binding))
        self.assertEqual(len(self.api.writes),1)

    def test_missing_or_corrupt_journal_never_becomes_automatic_bootstrap(self):
        for kind in ('missing','corrupt'):
            provider=self.provider(FakeAuditAPI(self.binding),config())
            path=provider.attempt_journal.path
            if kind=='missing': path.unlink()
            else: path.write_bytes(b'not a database')
            with self.subTest(kind=kind),self.assertRaises(RuntimeError):
                provider.reserve_once(self.binding)
            self.assertFalse(provider.api.writes)
            if kind=='missing': self.assertFalse(path.exists())

    def test_local_commit_ack_loss_or_bad_readback_blocks_before_external_write(self):
        for operation in ('reserve','transition','read'):
            provider=self.provider(FakeAuditAPI(self.binding),config())
            journal=provider.attempt_journal
            original=getattr(journal,operation)
            def fail(*args,**kwargs):
                result=original(*args,**kwargs)
                if operation=='read': return {**result,'state':'RESERVED'}
                raise RuntimeError('synthetic-local-sensitive-error')
            with patch.object(journal,operation,side_effect=fail),self.assertRaises(RuntimeError) as error:
                provider.reserve_once(self.binding)
            self.assertNotIn('synthetic-local-sensitive-error',str(error.exception))
            self.assertFalse(provider.api.writes)
            reopened=audit.GitHubAudit(provider.api,config(),Ledger(journal.path))
            self.assertIsNone(reopened.reserve_once(self.binding))
            self.assertFalse(provider.api.writes)


# Subprocess crash harness. All "network" calls are FakeAuditAPI in memory.
# A local marker counts invocation, independent of the disposable remote fake.
CHILD = r'''
from pathlib import Path
import os,sys
sys.path[:0]=[str(Path.cwd()/'scripts'),str(Path.cwd()/'tests')]
from ovh_controller_audit_test import FakeAuditAPI,binding_fixture,config
from owner_bound_dispatch_ledger import Ledger
from ovh_controller_audit import GitHubAudit
path,marker,mode=sys.argv[1:]
binding=binding_fixture()
journal=Ledger(path)
api=FakeAuditAPI(binding)
original_reserve=journal.reserve
def reserve(*args):
    result=original_reserve(*args)
    if mode=='crash_after_reserve': os._exit(23)
    return result
journal.reserve=reserve
original_transition=journal.transition
def transition(*args):
    original_transition(*args)
    if mode=='crash_before_post': os._exit(23)
journal.transition=transition
original_commit=api.commit_once
def commit(*args):
    with open(marker,'ab',buffering=0) as out:
        out.write(b'called\n')
        os.fsync(out.fileno())
    if mode=='crash_during_post': os._exit(23)
    return original_commit(*args)
api.commit_once=commit
result=GitHubAudit(api,config(),journal).reserve_once(binding)
print('BLOCKED' if result is None else 'FRESH')
'''


class RestartProcessTests(unittest.TestCase):
    def test_process_death_before_or_during_post_then_empty_remote_cannot_retry(self):
        for mode,expected_calls in (('crash_after_reserve',0),('crash_before_post',0),
                                    ('crash_during_post',1)):
            with self.subTest(mode=mode),tempfile.TemporaryDirectory() as directory:
                path=Path(directory)/'attempt.sqlite'
                marker=Path(directory)/'requests.log'
                Ledger.provision(path)
                args=[sys.executable,'-B','-c',CHILD,str(path),str(marker)]
                crashed=subprocess.run(args+[mode],cwd=ROOT,capture_output=True,timeout=15)
                self.assertEqual(crashed.returncode,23,crashed.stderr)
                reopened=subprocess.run(args+['normal'],cwd=ROOT,capture_output=True,timeout=15)
                self.assertEqual(reopened.returncode,0,reopened.stderr)
                self.assertEqual(reopened.stdout,b'BLOCKED\n')
                calls=marker.read_bytes().splitlines() if marker.exists() else []
                self.assertEqual(len(calls),expected_calls)

    def test_independent_processes_share_one_durable_global_attempt(self):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'attempt.sqlite'
            marker=Path(directory)/'requests.log'
            Ledger.provision(path)
            args=[sys.executable,'-B','-c',CHILD,str(path),str(marker),'normal']
            children=[subprocess.Popen(args,cwd=ROOT,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                      for _ in range(2)]
            try:
                results=[child.communicate(timeout=15) for child in children]
                for child,(_,error) in zip(children,results): self.assertEqual(child.returncode,0,error)
                self.assertCountEqual([out for out,_ in results],[b'FRESH\n',b'BLOCKED\n'])
                self.assertEqual(marker.read_bytes(),b'called\n')
            finally:
                for child in children:
                    if child.poll() is None:
                        child.kill()
                        child.communicate()


class NetworkSeamTests(unittest.TestCase):
    def test_fixed_mutation_only_expected_head_no_raw_body_or_other_repo_routes(self):
        api=audit.AuditAPI('synthetic-audit-only-token')
        raw=canonical({'schema':1,'claims':[binding_fixture()]}).encode()+b'\n'
        with patch.object(api,'_request',return_value={}) as request:
            api.commit_once(ANCHOR,raw)
        url,payload=request.call_args.args
        self.assertEqual(url,audit.GRAPHQL)
        self.assertEqual(payload['query'],audit.MUTATION)
        data=payload['variables']['input']
        self.assertEqual(data['expectedHeadOid'],ANCHOR)
        self.assertEqual(data['branch'],{'repositoryNameWithOwner':audit.REPOSITORY,'branchName':'main'})
        self.assertEqual(data['fileChanges'],{'additions':[{'path':'claims.json',
            'contents':base64.b64encode(raw).decode()}]})
        with patch.object(api,'_request',side_effect=AssertionError('Unexpected network')):
            for path in ('/rulesets/41?bypass=true','https://other','/git/refs','/contents/claims.json','/../Veiling'):
                with self.assertRaises(ValueError): api.get(path)
            with self.assertRaises(ValueError): api.commit_once(ANCHOR,audit.BOOTSTRAP)

    def test_request_uses_no_redirect_bounded_read_and_hides_api_errors(self):
        class Reply:
            status=200
            def __enter__(self): return self
            def __exit__(self,*_): return False
            def geturl(self): return audit.BASE
            def read(self,n):
                if n != audit.MAX_REPLY+1: raise AssertionError('Unbounded API read')
                return b'{}'
        opener=Mock();opener.open.return_value=Reply()
        api=audit.AuditAPI('synthetic-audit-only-token')
        with patch.object(audit,'build_opener',return_value=opener) as factory:
            self.assertEqual(api.get(''),{})
        self.assertIsInstance(factory.call_args.args[0],audit.NoRedirect)
        req=opener.open.call_args.args[0]
        self.assertEqual(req.full_url,audit.BASE)
        self.assertEqual(req.get_method(),'GET')
        self.assertEqual(req.headers['Authorization'],'Bearer synthetic-audit-only-token')
        opener.open.side_effect=TimeoutError('synthetic-audit-only-token')
        with patch.object(audit,'build_opener',return_value=opener), self.assertRaisesRegex(RuntimeError,'NO RETRY') as failure:
            api.get('')
        self.assertNotIn('synthetic-audit-only-token',str(failure.exception))


if __name__=='__main__':
    unittest.main()
