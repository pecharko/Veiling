"""Whole dormant chain: local fixtures only, never GitHub/SSH/OVH access.

Run the same cases with a SQL model locally and real PHP/MySQL in required CI.
Only transport boundaries are substituted; all production gates remain real.
"""
import copy
import hmac
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path[:0] = [str(ROOT/'scripts'), str(ROOT/'tests')]
import owner_bound_dispatch as guard
import owner_bound_dispatch_service as service
import ovh_controller_audit as audit
import ovh_controller_claim_adapter as adapter
import ovh_controller_claim_ssh as ssh
from owner_bound_dispatch_ledger import Ledger, canonical, binding_digest
from owner_bound_dispatch_integration_test import API, CI_QUERY, TARGET_QUERY, NONCE
from owner_bound_dispatch_service_test import SECRET, envelope, request
from ovh_controller_audit_test import FakeAuditAPI, config as audit_config
from ovh_controller_claim_adapter_test import KEY, TIME, SqlModel, binding_fixture, ovh_api
from ovh_controller_claim_ssh_test import config as ssh_config
from shared_ovh_controller_adapter_test import NOW


class LocalRunner:
    """Fake SSH boundary and SQL state; preserves the actual signed wire."""
    def __init__(self):
        self.config=ssh_config()
        self.calls=[]
        self.operations=[]
        self.store=SqlModel()
        self.mode='normal'
        self.after_reserve=None

    def reset(self):
        self.store.rows={}

    def inspect(self):
        return [{'state':r['state'],'claim_state':
                 'DISPATCH_UNKNOWN' if r['state'] in ('ACCEPTED','UNKNOWN') else r['state']}
                for r in self.store.rows.values()]

    def execute(self, payload, operation):
        frame=json.loads(payload)
        raw=frame['body'].encode()
        if not hmac.compare_digest(frame['signature'],adapter.mac(KEY,adapter.REQUEST_DOMAIN,raw)):
            raise AssertionError('Synthetic request MAC invalid')
        r=json.loads(raw)
        binding=r['binding']
        adapter.validate_binding(binding)
        if operation=='reserve':
            outcome='RESERVED' if self.store.reserve(binding) else 'CONFLICT'
            record=None
        elif operation=='transition':
            self.store.transition(binding,r['from_state'],r['to_state'])
            outcome,record=r['to_state'],None
        else:
            outcome='RECORD'
            row=self.store.read_bound(binding)
            record={'binding':row['binding'],'state':row['state']}
        if self.mode in ('reserve-unknown','accepted-unknown'):
            raise TimeoutError('synthetic-sensitive-commit-error')
        body=canonical({'schema':1,'operation':operation,'request_nonce':r['request_nonce'],
                        'binding_sha256':binding_digest(binding),'outcome':outcome,'record':record}).encode()
        return canonical({'body':body.decode(),'signature':adapter.mac(KEY,adapter.RESPONSE_DOMAIN,body)}).encode()+b'\n',b'',0

    def __call__(self, argv, payload, limit, timeout):
        self.calls.append((argv,payload))
        known=next(v.split('=',1)[1] for v in argv if v.startswith('UserKnownHostsFile='))
        if Path(known).read_text()!=self.config.known_host+'\n':
            raise AssertionError('Wrong synthetic hostkey pin')
        if not payload:
            if self.mode=='hash-drift': return b'wrong\n',b'',0
            return ''.join(h+'  '+p+'\n' for h,p in zip(
                (self.config.executor_sha256,self.config.store_sha256),self.config.paths())).encode(),b'',0
        r=json.loads(json.loads(payload)['body'])
        operation=r['operation']
        self.operations.append(operation)
        mode=self.mode
        fault=(mode=='reserve-unknown' and operation=='reserve'
               or mode=='accepted-unknown' and operation=='transition' and r['to_state']=='ACCEPTED')
        self.mode=mode if fault else 'normal'
        try: result=self.execute(payload,operation)
        finally: self.mode=mode
        if operation=='reserve' and self.after_reserve: self.after_reserve()
        if mode=='response-tamper':
            data=json.loads(result[0]);data['signature']='0'*64
            return canonical(data).encode()+b'\n',b'',0
        return result


class PHPRunner(LocalRunner):
    def fixture(self, mode, payload=b''):
        result=subprocess.run([shutil.which('php'),str(ROOT/'tests/ovh_controller_fullchain_fixture.php'),mode,str(TIME)],
                              input=payload,capture_output=True,timeout=15,
                              env={**os.environ,'OVH_CLAIM_FULLCHAIN_TEST':'1'})
        return result.stdout,result.stderr,result.returncode

    def reset(self):
        out,err,code=self.fixture('reset')
        if code!=0 or err or json.loads(out)!={'reset':True}: raise AssertionError('Fixture reset failed')

    def inspect(self):
        out,err,code=self.fixture('inspect')
        if code!=0 or err: raise AssertionError('Fixture inspection failed')
        return json.loads(out)

    def execute(self,payload,operation):
        return self.fixture(self.mode,payload)


class FullChainTests(unittest.TestCase):
    runner_class=LocalRunner

    def setUp(self):
        self.directory=tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.journal=Ledger.provision(Path(self.directory.name)/'audit-attempt.sqlite')
        self.api=ovh_api()
        self.event=envelope(self.api)
        self.evidence=adapter.OvhEvidence('fake-read-token')
        self.read_patch=patch.object(guard.evidence,'github_get',side_effect=API(self.api))
        self.read_patch.start();self.addCleanup(self.read_patch.stop)
        self.audit_api=FakeAuditAPI(binding_fixture())
        self.runner=self.runner_class()
        self.runner.reset()
        self.addCleanup(self.runner.reset)
        self.posts=[]
        self.post_unknown=False
        self.compose()

    def compose(self):
        self.audit=audit.GitHubAudit(self.audit_api,audit_config(),Ledger(self.journal.path))
        transport=ssh.SSHTransport(self.runner.config,runner=self.runner)
        self.ledger=adapter.RestoreSafeLedger(adapter.RemoteLedger(transport,KEY,clock=lambda:TIME),self.audit)
        self.sender=guard.GitHubDispatch('synthetic-dispatch-only-token')

    def attempt(self, event=None, environ=None):
        if environ is None: environ=request(self.event if event is None else event)
        authenticated=service.authenticate(environ,SECRET)
        reader=service.BoundReader(self.evidence,authenticated)
        parent=self
        class Response:
            status=204
            def __enter__(self): return self
            def __exit__(self,*args): return False
            def geturl(self): return guard.evidence.BASE+'/actions/workflows/shared-ovh-release.yml/dispatches'
        class Opener:
            def open(self,req,timeout):
                parent.posts.append(req)
                if parent.post_unknown: raise TimeoutError('synthetic-dispatch-sensitive-error')
                return Response()
        with patch.object(guard,'build_opener',return_value=Opener()):
            return guard._attempt_once(reader,self.ledger,self.sender,512,77,clock=lambda:NOW)

    def assert_no_writes(self):
        self.assertFalse(self.audit_api.writes)
        self.assertFalse(self.runner.calls)
        self.assertFalse(self.posts)
        with self.assertRaises(ValueError): self.journal.read(NONCE)

    def test_complete_authenticated_chain_and_exact_fixed_dispatch(self):
        result=self.attempt()
        self.assertEqual(result['status'],'ACCEPTED_RECONCILIATION_REQUIRED')
        binding=self.audit.lookup(NONCE)
        self.assertEqual(self.journal.read(NONCE)['state'],'ATTEMPTED')
        self.assertEqual(self.ledger.read(NONCE)['binding'],binding)
        self.assertEqual(self.runner.inspect(),[{'state':'ACCEPTED','claim_state':'DISPATCH_UNKNOWN'}])
        self.assertEqual(len(self.posts),1)
        req=self.posts[0]
        self.assertEqual(req.full_url,guard.evidence.BASE+'/actions/workflows/shared-ovh-release.yml/dispatches')
        self.assertEqual(json.loads(req.data),{'ref':'main','inputs':{'release_id':binding['release_id'],
            'confirmation':'DEPLOY-'+binding['release_id']+'-'+binding['main_sha']}})
        self.assertFalse(result['production_accepted'])
        self.assertFalse(result['retry_allowed'])

    def test_unsigned_event_cannot_enter_chain(self):
        env=request(self.event);env['HTTP_X_HUB_SIGNATURE_256']='sha256='+'0'*64
        with self.assertRaises(service.AuthenticationError): self.attempt(environ=env)
        self.assert_no_writes()

    def test_signed_comment_differs_from_independent_evidence_blocks_before_journal(self):
        self.api['/issues/comments/77']['body']+='\nchanged'
        self.assertEqual(self.attempt()['status'],'EVIDENCE_OR_RESERVATION_UNKNOWN_STOP_NO_RETRY')
        self.assert_no_writes()

    def test_failed_mysql_push_ci_blocks_before_journal(self):
        self.api[CI_QUERY]['workflow_runs'][-1]['conclusion']='failure'
        self.assertEqual(self.attempt()['status'],'EVIDENCE_OR_RESERVATION_UNKNOWN_STOP_NO_RETRY')
        self.assert_no_writes()

    def test_missing_journal_denies_all_downstream_writes(self):
        self.ledger.audit=audit.GitHubAudit(self.audit_api,audit_config())
        self.assertEqual(self.attempt()['status'],'EVIDENCE_OR_RESERVATION_UNKNOWN_STOP_NO_RETRY')
        self.assert_no_writes()

    def test_unknown_audit_without_commit_reopen_cannot_reach_ssh(self):
        self.audit_api.mode='timeout_without_commit'
        self.assertEqual(self.attempt()['status'],'EVIDENCE_OR_RESERVATION_UNKNOWN_STOP_NO_RETRY')
        self.compose();self.audit_api.mode=None
        self.assertEqual(self.attempt()['status'],'REPLAY_OR_ACTIVE_CLAIM')
        self.assertEqual(len(self.audit_api.writes),1)
        self.assertFalse(self.runner.calls);self.assertFalse(self.posts)

    def test_remote_code_drift_burns_audit_but_sends_no_claim_payload(self):
        self.runner.mode='hash-drift'
        self.assertEqual(self.attempt()['status'],'EVIDENCE_OR_RESERVATION_UNKNOWN_STOP_NO_RETRY')
        self.assertEqual(len(self.audit_api.writes),1)
        self.assertEqual(len(self.runner.calls),1)
        self.assertEqual(self.runner.calls[0][1],b'')
        self.assertFalse(self.posts)
        self.compose();self.runner.mode='normal'
        self.assertEqual(self.attempt()['status'],'REPLAY_OR_ACTIVE_CLAIM')
        self.assertEqual(len(self.runner.calls),1)

    def test_unknown_sql_commit_persists_claim_but_never_dispatches_or_retries(self):
        self.runner.mode='reserve-unknown'
        self.assertEqual(self.attempt()['status'],'EVIDENCE_OR_RESERVATION_UNKNOWN_STOP_NO_RETRY')
        self.assertEqual(self.runner.inspect()[0]['state'],'RESERVED')
        count=len(self.runner.calls)
        self.compose();self.runner.mode='normal'
        self.assertEqual(self.attempt()['status'],'REPLAY_OR_ACTIVE_CLAIM')
        self.assertEqual(len(self.runner.calls),count)
        self.assertFalse(self.posts)

    def test_response_mac_tamper_cannot_authorize_dispatch(self):
        self.runner.mode='response-tamper'
        self.assertEqual(self.attempt()['status'],'EVIDENCE_OR_RESERVATION_UNKNOWN_STOP_NO_RETRY')
        self.assertEqual(self.runner.inspect()[0]['state'],'RESERVED')
        self.assertFalse(self.posts)

    def test_owner_drift_after_sql_reservation_retains_both_locks(self):
        original=self.api['/issues/comments/77']['body']
        self.runner.after_reserve=lambda:self.api['/issues/comments/77'].update(body=original+'\nchanged')
        self.assertEqual(self.attempt()['status'],'REVALIDATION_STOP_NO_RETRY')
        self.assertEqual(self.runner.inspect()[0]['state'],'ABORTED')
        self.assertEqual(self.journal.read(NONCE)['state'],'ATTEMPTED')
        self.api['/issues/comments/77']['body']=original
        self.assertEqual(self.attempt()['status'],'REPLAY_OR_ACTIVE_CLAIM')
        self.assertFalse(self.posts)

    def test_unknown_dispatch_once_and_reconciliation_never_confirms_acceptance(self):
        self.post_unknown=True
        self.assertEqual(self.attempt()['status'],'DISPATCH_UNKNOWN_STOP_NO_RETRY')
        self.assertEqual(self.runner.inspect()[0]['state'],'UNKNOWN')
        self.compose()
        self.assertEqual(self.attempt()['status'],'REPLAY_OR_ACTIVE_CLAIM')
        run=copy.deepcopy(self.api[CI_QUERY]['workflow_runs'][0])
        run.update(id=901,path='.github/workflows/shared-ovh-release.yml',event='workflow_dispatch')
        self.api[TARGET_QUERY]={'total_count':1,'workflow_runs':[run]}
        review=guard.reconcile(self.evidence,self.ledger,NONCE)
        self.assertEqual(review['candidate_run_ids'],[901])
        for flag in ('dispatch_identity_confirmed','retry_allowed','production_accepted',
                     'independent_ovh_verified','browser_accepted'): self.assertFalse(review[flag])
        self.assertEqual(len(self.posts),1)

    def test_unknown_final_commit_after_204_cannot_dispatch_twice(self):
        self.runner.mode='accepted-unknown'
        self.assertEqual(self.attempt()['status'],'DISPATCH_UNKNOWN_STOP_NO_RETRY')
        self.assertEqual(self.runner.inspect()[0]['state'],'ACCEPTED')
        self.compose();self.runner.mode='normal'
        self.assertEqual(self.attempt()['status'],'REPLAY_OR_ACTIVE_CLAIM')
        self.assertEqual(len(self.posts),1)

    def test_real_sql_reset_does_not_reset_independent_authority(self):
        self.assertEqual(self.attempt()['status'],'ACCEPTED_RECONCILIATION_REQUIRED')
        self.runner.reset()
        count=len(self.runner.calls)
        self.compose()
        self.assertEqual(self.attempt()['status'],'REPLAY_OR_ACTIVE_CLAIM')
        self.assertEqual(self.runner.inspect(),[])
        self.assertEqual(len(self.runner.calls),count)
        self.assertEqual(len(self.posts),1)


class PHPMySQLFullChainTests(FullChainTests):
    runner_class=PHPRunner

    @classmethod
    def setUpClass(cls):
        if os.environ.get('OVH_CLAIM_REQUIRE_FULLCHAIN')!='1':
            raise unittest.SkipTest('Real isolated PHP/MySQL chain is mandatory in CI')
        if not shutil.which('php'): raise AssertionError('Required full-chain PHP missing')


if __name__=='__main__': unittest.main()
