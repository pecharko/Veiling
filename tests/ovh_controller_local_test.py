"""Operator CLI and private production bootstrap, with local-only fixtures."""
import base64
from dataclasses import asdict,replace
import hashlib
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import Mock,patch

ROOT=Path(__file__).resolve().parents[1]
sys.path[:0]=[str(ROOT/'scripts'),str(ROOT/'tests')]
import ovh_controller_local as local
import owner_bound_dispatch as guard
import ovh_controller_claim_adapter as adapter
import ovh_controller_claim_ssh as ssh
from owner_bound_dispatch_ledger import Ledger,canonical
from owner_bound_dispatch_integration_test import API,NONCE
from ovh_controller_audit_test import FakeAuditAPI,config as audit_config
from ovh_controller_claim_adapter_test import KEY,binding_fixture,ovh_api
from ovh_controller_claim_ssh_test import config as ssh_config
from ovh_controller_fullchain_test import LocalRunner,PHPRunner
from shared_ovh_controller_adapter_test import NOW


def private_file(path,data):
    path.write_bytes(data);path.chmod(0o600)
    return str(path)


class OperatorTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name).resolve()
        self.paths={}
        for name,data in [('read_token',b'fake-read-token'),('audit_token',b'synthetic-audit-token'),
                          ('dispatch_token',b'synthetic-dispatch-token'),('claim_hmac',KEY)]:
            self.paths[name]=private_file(self.root/name,data)
        journal=self.root/'attempt.sqlite';Ledger.provision(journal)
        self.paths['attempt_journal']=str(journal)
        key=private_file(self.root/'ssh-key',b'synthetic-not-a-real-private-key')
        self.ssh=replace(ssh_config(),identity_file=key,runtime_sha256='d'*64,runtime_config_sha256='e'*64)
        self.cfg={'schema':1,'issue_id':512,'comment_id':77,'audit':asdict(audit_config()),
                  'ssh':asdict(self.ssh),'paths':self.paths}
        self.path=self.root/'controller.json'
        self.save()

    def save(self):
        raw=(canonical(self.cfg)+'\n').encode()
        private_file(self.path,raw);self.pin=hashlib.sha256(raw).hexdigest()

    def cli(self,mode,*extra):
        with patch('sys.stdout',new_callable=io.StringIO) as out:
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
                 lambda:self.cfg['ssh'].update(runtime_sha256=None,runtime_config_sha256=None),
                 lambda:self.cfg['paths'].update(dispatch_token=self.paths['audit_token']),
                 lambda:Path(self.paths['read_token']).chmod(0o644)]
        for change in changes:
            original=json.loads(json.dumps(self.cfg));change();self.save()
            with self.assertRaises(Exception): local.configuration(str(self.path),self.pin)
            self.cfg=original;Path(self.paths['read_token']).chmod(0o600)
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

    def test_review_reads_only_reader_credential_and_cannot_reserve_or_dispatch(self):
        api=API(ovh_api());original=local.token;read=[]
        def tok(path): read.append(path);return original(path)
        with patch.object(local,'token',side_effect=tok),patch.object(guard.evidence,'github_get',side_effect=api), \
             patch.object(local,'datetime') as clock, \
             patch.object(local,'components',side_effect=AssertionError('Writer composition')):
            clock.now.return_value=NOW
            code,result=self.cli('review')
        self.assertEqual((code,result['status']),(0,'VERIFIED_READ_ONLY'))
        self.assertEqual(read,[self.paths['read_token']])
        self.assertNotIn(NONCE,json.dumps(result))

    def test_errors_are_redacted_and_attempt_is_not_retried(self):
        with patch.object(local,'components',side_effect=RuntimeError('synthetic-secret-path-token')) as compose:
            code,result=self.cli('attempt')
        self.assertEqual((code,result['status']),(75,'STOP_NO_RETRY'))
        self.assertEqual(compose.call_count,1)
        self.assertNotIn('synthetic-secret',json.dumps(result))

    def test_runtime_requires_both_pins_and_three_exact_code_paths(self):
        for values in ({'runtime_sha256':None},{'runtime_config_sha256':None},
                       {'runtime_sha256':'bad'},{'runtime_config_sha256':True}):
            with self.subTest(values=values),self.assertRaises(ValueError): replace(self.ssh,**values).validate()
        self.assertEqual(len(self.ssh.paths()),3)
        self.assertTrue(self.ssh.paths()[2].endswith('/ovh_controller_claim_runtime.php'))
        self.assertEqual(self.ssh.hashes(),(self.ssh.executor_sha256,self.ssh.store_sha256,self.ssh.runtime_sha256))

    def full_attempt(self,runner):
        api=ovh_api();authority=FakeAuditAPI(binding_fixture());posts=[]
        class Reply:
            status=204
            def __enter__(self): return self
            def __exit__(self,*args): return False
            def geturl(self): return guard.evidence.BASE+'/actions/workflows/shared-ovh-release.yml/dispatches'
        opener=Mock()
        def post(req,timeout): posts.append(req);return Reply()
        opener.open.side_effect=post
        transport=lambda cfg:ssh.SSHTransport(cfg,runner=runner)
        with patch.object(guard.evidence,'github_get',side_effect=API(api)), \
             patch.object(local,'AuditAPI',return_value=authority), \
             patch.object(local,'SSHTransport',side_effect=transport), \
             patch.object(guard,'datetime') as clock, \
             patch.object(guard,'build_opener',return_value=opener):
            clock.now.return_value=NOW
            first=self.cli('attempt')
            second=self.cli('attempt')
        self.assertEqual(first[0],0)
        self.assertEqual(first[1]['status'],'ACCEPTED_RECONCILIATION_REQUIRED')
        self.assertEqual(second[1]['status'],'REPLAY_OR_ACTIVE_CLAIM')
        self.assertEqual(len(posts),1)
        self.assertEqual(len(authority.writes),1)

    def test_real_cli_composes_complete_chain_and_refuses_second_attempt(self):
        runner=LocalRunner();runner.config=self.ssh
        # Existing fake runner knows the two-code baseline; support the real
        # three-file v1 preflight while all production transport code stays real.
        original=runner.__call__
        def run(argv,payload,limit,timeout):
            if not payload:
                return ''.join(h+'  '+p+'\n' for h,p in zip(self.ssh.hashes(),self.ssh.paths())).encode(),b'',0
            return original(argv,payload,limit,timeout)
        self.full_attempt(run)


class PrivateRuntimeTests(OperatorTests):
    @classmethod
    def setUpClass(cls):
        if os.environ.get('OVH_CLAIM_REQUIRE_FULLCHAIN')!='1':
            raise unittest.SkipTest('Real private PHP/PDO bootstrap is required in CI')
        if not shutil.which('php'): raise AssertionError('Mandatory PHP missing')

    def setUp(self):
        super().setUp()
        self.fixture=PHPRunner();self.fixture.reset();self.addCleanup(self.fixture.reset)
        private=self.root/'private';version=private/('a'*40);version.mkdir(parents=True)
        (private/'config').mkdir(mode=0o700)
        hashes=[]
        for name in ('ovh_controller_claim_executor.php','ovh_controller_claim_store.php','ovh_controller_claim_runtime.php'):
            data=(ROOT/'scripts'/name).read_bytes();(version/name).write_bytes(data)
            hashes.append(hashlib.sha256(data).hexdigest())
        cfg={'schema':1,'dsn':'mysql:host=127.0.0.1;port=3306;dbname=claim_ci;charset=utf8mb4',
             'user':'ci','password':'local-claims-ci','hmac_base64':base64.b64encode(KEY).decode()}
        self.remote_config=private/'config'/('a'*40+'.json')
        raw=(canonical(cfg)+'\n').encode();private_file(self.remote_config,raw)
        self.runtime=version/'ovh_controller_claim_runtime.php'
        self.ssh=replace(self.ssh,private_root=str(private),executor_sha256=hashes[0],store_sha256=hashes[1],
                         runtime_sha256=hashes[2],runtime_config_sha256=hashlib.sha256(raw).hexdigest())
        self.cfg['ssh']=asdict(self.ssh);self.save()

    def php(self,args,payload=b''):
        p=subprocess.run([shutil.which('php'),str(self.runtime),*args],input=payload,capture_output=True,timeout=10)
        return p.stdout,p.stderr,p.returncode

    def test_private_bootstrap_direct_default_disabled(self):
        out,err,code=self.php([])
        self.assertEqual(code,78);self.assertEqual(err,b'')
        self.assertEqual(json.loads(out)['status'],'DISABLED')

    def test_config_drift_permissions_and_unsigned_input_fail_without_claim(self):
        for pin,mode,payload in [('0'*64,0o600,b''),
                                 (self.ssh.runtime_config_sha256,0o644,b''),
                                 (self.ssh.runtime_config_sha256,0o600,b'{}\n')]:
            self.remote_config.chmod(mode)
            out,err,code=self.php([pin],payload)
            self.assertEqual(code,65);self.assertEqual(err,b'')
            self.assertEqual(json.loads(out)['status'],'REJECTED')
            self.assertEqual(self.fixture.inspect(),[])
        self.remote_config.chmod(0o600)

    def test_local_cli_through_real_private_bootstrap_and_mysql(self):
        calls=[]
        def run(argv,payload,limit,timeout):
            calls.append((argv,payload))
            if not payload:
                return ''.join(h+'  '+p+'\n' for h,p in zip(self.ssh.hashes(),self.ssh.paths())).encode(),b'',0
            self.assertEqual(argv[-1],self.ssh.php_binary+' -f '+str(self.runtime)+' -- '+self.ssh.runtime_config_sha256)
            return self.php([self.ssh.runtime_config_sha256],payload)
        self.full_attempt(run)
        self.assertEqual(self.fixture.inspect(),[{'state':'ACCEPTED','claim_state':'DISPATCH_UNKNOWN'}])
        self.assertEqual(len(calls),6)  # reserve, attempted, accepted: preflight+executor each.


if __name__=='__main__': unittest.main()
