"""Runnable macOS operator chain with real SQLite, fake external HTTP only."""
import hashlib
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import Mock,patch

ROOT=Path(__file__).resolve().parents[1]
sys.path[:0]=[str(ROOT/'scripts'),str(ROOT/'tests')]
import ovh_controller_local as local
import owner_bound_dispatch as guard
from owner_bound_dispatch_ledger import Ledger,canonical
from owner_bound_dispatch_integration_test import API,NONCE,CI_QUERY
from ovh_controller_claim_adapter_test import ovh_api
from shared_ovh_controller_adapter_test import NOW


def private_file(path,data):
    path.write_bytes(data);path.chmod(0o600)
    return str(path)


class OperatorTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name).resolve()
        self.paths={name:private_file(self.root/name,data) for name,data in (
            ('read_token',b'fake-read-token'),('dispatch_token',b'synthetic-dispatch-token'))}
        journal=self.root/'attempt.sqlite';Ledger.provision(journal)
        self.paths['attempt_journal']=str(journal)
        self.cfg={'schema':1,'issue_id':512,'comment_id':77,'paths':self.paths}
        self.path=self.root/'controller.json';self.save()
        self.api=ovh_api();self.posts=[];self.unknown=False
        parent=self
        class Reply:
            status=204
            def __enter__(self): return self
            def __exit__(self,*args): return False
            def geturl(self): return guard.evidence.BASE+'/actions/workflows/shared-ovh-release.yml/dispatches'
        self.opener=Mock()
        def post(req,timeout):
            parent.posts.append(req)
            if parent.unknown: raise TimeoutError('synthetic-private-error')
            return Reply()
        self.opener.open.side_effect=post

    def save(self):
        raw=(canonical(self.cfg)+'\n').encode();private_file(self.path,raw)
        self.pin=hashlib.sha256(raw).hexdigest()

    def cli(self,mode,*extra):
        with patch('sys.stdout',new_callable=io.StringIO) as out, \
             patch.object(guard.evidence,'github_get',side_effect=API(self.api)), \
             patch.object(guard,'datetime') as clock, \
             patch.object(local,'datetime') as review_clock, \
             patch.object(guard,'build_opener',return_value=self.opener):
            clock.now.return_value=NOW;review_clock.now.return_value=NOW
            code=local.main([mode,'--config',str(self.path),'--config-sha256',self.pin,*extra])
        return code,json.loads(out.getvalue())

    def test_default_disabled_before_files_secrets_or_network(self):
        with patch.object(local,'configuration',side_effect=AssertionError('Configuration read')), \
             patch('sys.stdout',new_callable=io.StringIO) as out:
            self.assertEqual(local.main([]),78)
        self.assertEqual(json.loads(out.getvalue())['status'],'DISABLED')

    def test_check_validates_files_and_pins_without_credentials_or_network(self):
        with patch.object(local,'token',side_effect=AssertionError('Credential read')), \
             patch.object(local,'components',side_effect=AssertionError('Network composition')):
            code,result=self.cli('check')
        self.assertEqual((code,result['status']),(0,'LOCAL_CONFIG_VALID_NO_NETWORK'))

    def test_config_drift_unknown_fields_nonprivate_files_symlinks_and_shared_paths_fail(self):
        changes=[lambda:self.cfg.update(untrusted_activation=True),
                 lambda:self.cfg['paths'].update(dispatch_token=self.paths['read_token']),
                 lambda:Path(self.paths['read_token']).chmod(0o644)]
        for change in changes:
            original=json.loads(json.dumps(self.cfg));change();self.save()
            with self.assertRaises(Exception): local.configuration(str(self.path),self.pin)
            self.cfg=original;self.paths=self.cfg['paths'];Path(self.paths['read_token']).chmod(0o600)
        self.save()
        with self.assertRaises(ValueError): local.configuration(str(self.path),'0'*64)
        link=self.root/'link';link.symlink_to(self.path)
        with self.assertRaises(ValueError): local.configuration(str(link),self.pin)

    def test_explicit_local_journal_creation_is_exclusive_and_never_networks(self):
        path=self.root/'new.sqlite'
        with patch.object(local,'components',side_effect=AssertionError('Network')), \
             patch('sys.stdout',new_callable=io.StringIO):
            self.assertEqual(local.main(['init-journal','--journal',str(path)]),0)
            before=path.read_bytes()
            self.assertEqual(local.main(['init-journal','--journal',str(path)]),75)
        self.assertEqual(path.read_bytes(),before)
        self.assertEqual(path.stat().st_mode & 0o777,0o600)
        self.root.chmod(0o755)
        try:
            with self.assertRaises(ValueError): local.configuration(str(self.path),self.pin)
        finally: self.root.chmod(0o700)

    def test_review_reads_only_reader_credential_and_cannot_reserve_or_dispatch(self):
        original=local.token;read=[]
        def tok(path): read.append(path);return original(path)
        with patch.object(local,'token',side_effect=tok), \
             patch.object(local,'components',side_effect=AssertionError('Writer composition')):
            code,result=self.cli('review')
        self.assertEqual((code,result['status']),(0,'VERIFIED_READ_ONLY'))
        self.assertEqual(read,[self.paths['read_token']]);self.assertFalse(self.posts)
        self.assertNotIn(NONCE,json.dumps(result))
        with self.assertRaises(ValueError): Ledger(self.paths['attempt_journal']).read(NONCE)

    def test_errors_are_redacted_and_attempt_is_not_retried(self):
        with patch.object(local,'components',side_effect=RuntimeError('synthetic-secret-path-token')) as compose:
            code,result=self.cli('attempt')
        self.assertEqual((code,result['status']),(75,'STOP_NO_RETRY'))
        self.assertEqual(compose.call_count,1);self.assertNotIn('synthetic-secret',json.dumps(result))

    def test_cli_complete_one_post_and_reopen_refuses_same_authorization(self):
        first=self.cli('attempt');second=self.cli('attempt')
        self.assertEqual((first[0],first[1]['status']),(0,'ACCEPTED_RECONCILIATION_REQUIRED'))
        self.assertEqual(second[1]['status'],'REPLAY_OR_ACTIVE_CLAIM')
        self.assertEqual(Ledger(self.paths['attempt_journal']).read(NONCE)['state'],'ACCEPTED')
        self.assertEqual(len(self.posts),1)
        payload=json.loads(self.posts[0].data)
        self.assertEqual(set(payload),{'ref','inputs'});self.assertEqual(payload['ref'],'main')
        self.assertEqual(set(payload['inputs']),{'release_id','confirmation'})
        self.assertFalse(first[1]['production_accepted'])

    def test_unknown_post_is_persistent_and_reconcile_does_not_load_dispatch_token(self):
        self.unknown=True
        self.assertEqual(self.cli('attempt')[1]['status'],'DISPATCH_UNKNOWN_STOP_NO_RETRY')
        self.assertEqual(Ledger(self.paths['attempt_journal']).read(NONCE)['state'],'UNKNOWN')
        self.assertEqual(self.cli('attempt')[1]['status'],'REPLAY_OR_ACTIVE_CLAIM')
        original=local.token;read=[]
        def tok(path): read.append(path);return original(path)
        with patch.object(local,'token',side_effect=tok):
            code,result=self.cli('reconcile','--nonce',NONCE)
        self.assertEqual((code,result['status']),(0,'READ_ONLY_RECONCILIATION'))
        self.assertEqual(read,[self.paths['read_token']]);self.assertEqual(len(self.posts),1)
        for flag in ('retry_allowed','production_accepted','dispatch_identity_confirmed','independent_ovh_verified'):
            self.assertFalse(result[flag])

    def test_failed_push_ci_blocks_before_journal_or_post(self):
        self.api[CI_QUERY]['workflow_runs'][-1]['conclusion']='failure'
        self.assertEqual(self.cli('attempt')[1]['status'],'EVIDENCE_OR_RESERVATION_UNKNOWN_STOP_NO_RETRY')
        self.assertFalse(self.posts)
        with self.assertRaises(ValueError): Ledger(self.paths['attempt_journal']).read(NONCE)

    def test_separate_process_uses_persistent_journal_instead_of_reinitializing(self):
        self.cli('attempt')
        source="import sys;sys.path.insert(0,'scripts');from owner_bound_dispatch_ledger import Ledger;from ovh_controller_local import configuration;cfg=configuration(sys.argv[1],sys.argv[2]);db=Ledger(cfg['paths']['attempt_journal']);row=db.read(sys.argv[3]);assert row['state']=='ACCEPTED';assert db.reserve(row['binding']) is False;print('BLOCKED')"
        p=subprocess.run([sys.executable,'-B','-c',source,str(self.path),self.pin,NONCE],
                         cwd=ROOT,capture_output=True,timeout=10)
        self.assertEqual(p.returncode,0,p.stderr);self.assertEqual(p.stdout,b'BLOCKED\n')


if __name__=='__main__': unittest.main()
