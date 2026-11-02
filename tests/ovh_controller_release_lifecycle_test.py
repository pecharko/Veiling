"""Real SQLite lifecycle and GitHub-bound completion; no external writes."""
from concurrent.futures import ThreadPoolExecutor
import copy
from datetime import timedelta
import hashlib
import io
import json
from pathlib import Path
import sqlite3
import sys
import tempfile
import unittest
from unittest.mock import patch
import zipfile

ROOT=Path(__file__).resolve().parents[1]
sys.path[:0]=[str(ROOT/'scripts'),str(ROOT/'tests')]
import ovh_controller_release_lifecycle as life
from owner_bound_dispatch_ledger import Ledger, canonical, binding_digest
from ovh_controller_claim_adapter import OvhEvidence
from ovh_controller_claim_adapter_test import binding_fixture
from shared_ovh_controller_adapter_test import NOW, fmt
from shared_ovh_controller_evidence_test import git_item


def zipped(files):
    out=io.BytesIO()
    with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED) as z:
        for k,v in files.items(): z.writestr(k,v)
    return out.getvalue()


def proof_api(b):
    run={'id':123,'head_sha':b['main_sha'],'head_branch':'main','event':'workflow_dispatch',
         'run_attempt':1,'path':'.github/workflows/shared-ovh-release.yml','status':'completed',
         'conclusion':'success','repository':{'full_name':b['repository']},
         'head_repository':{'full_name':b['repository']},'updated_at':fmt(NOW-timedelta(minutes=2))}
    user={'id':b['owner_id'],'login':'pecharko','type':'User'}
    issue_url=life.api.BASE+'/issues/'+str(b['issue_id'])
    original={'id':b['comment_id'],'body':'synthetic-original','issue_url':issue_url,'user':user,
              'created_at':fmt(NOW-timedelta(minutes=5)),'updated_at':fmt(NOW-timedelta(minutes=5))}
    b['approval_sha256']=hashlib.sha256(original['body'].encode()).hexdigest()
    acceptance={'id':78,'body':life.completion_text(b,123),'issue_url':issue_url,'user':user,
                'created_at':fmt(NOW-timedelta(minutes=1)),'updated_at':fmt(NOW-timedelta(minutes=1))}
    # Existing files have genuine backup bytes; an absent file has none.
    previous={}; backup={}; source={}
    for r in b['files']:
        if r['remote_sha256']!='ABSENT':
            backup[r['path']]=b'original-fixture-bytes'
            r['remote_sha256']=hashlib.sha256(backup[r['path']]).hexdigest()
        previous[r['path']]=r['remote_sha256'];source[r['path']]=r['source_sha256']
    manifest={'schema':1,'files':b['files']}; raw=canonical(manifest).encode()
    b['manifest_sha256']=hashlib.sha256(raw).hexdigest()
    evidence={'schema':1,'release_id':b['release_id'],'release_sha':b['main_sha'],'run_id':123,
              'status':'VERIFIED','rollback_artifact':None,'browser_acceptance':'PENDING',
              'files':[{'path':r['path'],'source_sha256':r['source_sha256'],
                        'baseline_sha256':r['remote_sha256'],'readback_sha256':r['source_sha256']} for r in b['files']]}
    backup['manifest.json']=canonical({'release_id':b['release_id'],'release_sha':b['main_sha'],
                                      'previous':previous,'source':source}).encode()
    archives=[zipped({'deployment-evidence.json':canonical(evidence)}),zipped(backup)]
    artifacts=[{'id':n,'name':'shared-ovh-'+kind+'-123','expired':False,'expires_at':fmt(NOW+timedelta(days=6)),
                'workflow_run':{'id':123,'head_sha':b['main_sha']},
                'digest':'sha256:'+hashlib.sha256(blob).hexdigest()}
               for n,kind,blob in zip((10,11),('evidence','rollback'),archives)]
    return {'':{'full_name':b['repository'],'owner':user},
            '/issues/'+str(b['issue_id']):{'number':b['issue_id'],'title':'DEPLOY-AUTH: shared-ovh-release/'+b['release_id'],'user':user},
            '/issues/comments/'+str(b['comment_id']):original,'/issues/comments/78':acceptance,
            '/actions/runs/123':run,
            '/actions/workflows/shared-ovh-release.yml/runs?per_page=100':{'total_count':1,'workflow_runs':[run]},
            '/actions/runs/123/artifacts?per_page=100':{'total_count':2,'artifacts':artifacts},
            '/contents/releases/'+b['release_id']+'.json?ref='+b['main_sha']:git_item(raw),
            '/actions/artifacts/10/zip':archives[0],'/actions/artifacts/11/zip':archives[1]}


class LifecycleTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.path=Path(self.tmp.name)/'attempt.sqlite'
        self.binding=binding_fixture();self.api=proof_api(self.binding)
        self.ledger=life.MacReleaseLedger.provision(self.path)

    def accept(self):
        self.assertTrue(self.ledger.reserve(self.binding))
        self.ledger.transition(self.binding,'RESERVED','ATTEMPTED')
        self.ledger.transition(self.binding,'ATTEMPTED','ACCEPTED')

    def verify(self):
        def get(url,token):
            self.assertEqual(token,'synthetic-read-token')
            self.assertTrue(url.startswith(life.api.BASE))
            value=self.api[url[len(life.api.BASE):]]
            return value if type(value) is bytes else canonical(value).encode()
        with patch.object(life.api,'github_get',side_effect=get):
            return life.verify_completion(OvhEvidence('synthetic-read-token'),self.ledger,self.binding['nonce'],123,78,now=NOW)

    def test_completion_preserves_all_identity_constraints_but_allows_fresh_release(self):
        self.accept(); proof=self.verify()
        self.ledger.complete(self.binding,proof)
        reopened=life.MacReleaseLedger(self.path)
        row=reopened.read(self.binding['nonce'])
        self.assertEqual(row['state'],'COMPLETED');self.assertEqual(row['run_id'],123)
        self.assertEqual(row['completion']['acceptance_comment_id'],78)
        fresh={**self.binding,'nonce':'f'*64,'comment_id':79,'release_id':'next-release'}
        for fields in ({'nonce':self.binding['nonce']},{'comment_id':self.binding['comment_id']},
                       {'release_id':self.binding['release_id']}):
            self.assertFalse(reopened.reserve({**fresh,**fields}))
        self.assertTrue(reopened.reserve(fresh))
        with self.assertRaises(ValueError): reopened.complete(self.binding,proof)
        with self.assertRaises(ValueError): reopened.transition(self.binding,'COMPLETED','RESERVED')

    def test_unknown_or_unattempted_state_never_unlocks_and_json_flags_rejected(self):
        self.assertTrue(self.ledger.reserve(self.binding))
        for state in ('RESERVED','ATTEMPTED','UNKNOWN'):
            if state=='ATTEMPTED': self.ledger.transition(self.binding,'RESERVED','ATTEMPTED')
            if state=='UNKNOWN': self.ledger.transition(self.binding,'ATTEMPTED','UNKNOWN')
            with self.subTest(state=state),self.assertRaises(ValueError):self.verify()
        with self.assertRaises(ValueError):self.ledger.complete(self.binding,{'verified':True})
        self.assertFalse(self.ledger.reserve({**self.binding,'nonce':'f'*64,'comment_id':79,'release_id':'next-release'}))

    def test_atomic_completion_and_next_reservation_across_connections(self):
        self.accept();proof=self.verify()
        def close(_):
            try:life.MacReleaseLedger(self.path).complete(self.binding,proof);return True
            except ValueError:return False
        with ThreadPoolExecutor(max_workers=4) as pool:self.assertEqual(sum(pool.map(close,range(4))),1)
        def reserve(n):
            return life.MacReleaseLedger(self.path).reserve({**self.binding,'nonce':str(n)*64,'comment_id':80+n,'release_id':'next-release-'+str(n)})
        with ThreadPoolExecutor(max_workers=4) as pool:self.assertEqual(sum(pool.map(reserve,range(1,5))),1)

    def test_legacy_upgrade_retains_accepted_row_and_private_backup_without_unlock(self):
        self.path.unlink();old=Ledger.provision(self.path)
        old.reserve(self.binding);old.transition(self.binding,'RESERVED','ATTEMPTED');old.transition(self.binding,'ATTEMPTED','ACCEPTED')
        before=old.read(self.binding['nonce'])
        with self.assertRaises(ValueError):life.MacReleaseLedger(self.path)
        backup=life.MacReleaseLedger.upgrade(self.path)
        self.assertEqual(backup.stat().st_mode&0o777,0o600)
        self.assertEqual(Ledger(backup).read(self.binding['nonce']),before)
        self.ledger=life.MacReleaseLedger(self.path)
        self.assertEqual(self.ledger.read(self.binding['nonce'])['binding'],before['binding'])
        self.assertEqual(self.ledger.read(self.binding['nonce'])['state'],'ACCEPTED')
        self.assertFalse(self.ledger.reserve(self.binding))
        with self.assertRaises(ValueError):life.MacReleaseLedger.upgrade(self.path)
        self.assertEqual(Ledger(backup).read(self.binding['nonce']),before)
        self.ledger.complete(self.binding,self.verify())

    def test_upgrade_refuses_existing_backup_or_changed_schema_without_mutation(self):
        self.path.unlink();old=Ledger.provision(self.path);old.reserve(self.binding)
        backup=Path(str(self.path)+'.v1-backup.sqlite');backup.write_bytes(b'never overwrite')
        with self.assertRaises(FileExistsError):life.MacReleaseLedger.upgrade(self.path)
        self.assertEqual(old.read(self.binding['nonce'])['state'],'RESERVED')
        self.assertEqual(backup.read_bytes(),b'never overwrite')
        backup.unlink()
        with sqlite3.connect(self.path) as db:db.execute('DROP INDEX one_active_dispatch')
        with self.assertRaises(Exception):life.MacReleaseLedger.upgrade(self.path)
        self.assertFalse(backup.exists())

    def test_untrusted_owner_run_artifact_or_rollback_blocks_without_changing_claim(self):
        self.accept();original=copy.deepcopy(self.api)
        mutations=[
            lambda a:a['/issues/comments/78']['user'].update(id=9),
            lambda a:a['/issues/comments/78'].update(updated_at=fmt(NOW)),
            lambda a:a['/issues/comments/78'].update(body=life.completion_text(self.binding,124)),
            lambda a:a['/issues/comments/78'].update(created_at=fmt(NOW-timedelta(days=2)),updated_at=fmt(NOW-timedelta(days=2))),
            lambda a:a['/issues/comments/78'].update(issue_url=life.api.BASE+'/issues/999'),
            lambda a:a['/issues/comments/'+str(self.binding['comment_id'])].update(body='edited approval'),
            lambda a:a['/actions/runs/123'].update(updated_at=fmt(NOW)),
            lambda a:a['/actions/runs/123'].update(run_attempt=2),
            lambda a:a['/actions/runs/123'].update(head_sha='0'*40),
            lambda a:a['/actions/runs/123'].update(conclusion='failure'),
            lambda a:a['/actions/workflows/shared-ovh-release.yml/runs?per_page=100']['workflow_runs'].append(copy.deepcopy(a['/actions/runs/123'])),
            lambda a:a['/actions/runs/123/artifacts?per_page=100']['artifacts'][1].update(expired=True),
            lambda a:a['/actions/runs/123/artifacts?per_page=100']['artifacts'][1].update(expires_at=fmt(NOW)),
            lambda a:a['/actions/runs/123/artifacts?per_page=100']['artifacts'][0].update(digest='sha256:'+'0'*64),
            lambda a:a.update({'/actions/artifacts/11/zip':b'corrupt backup'}),
        ]
        for mutation in mutations:
            self.api=copy.deepcopy(original);mutation(self.api)
            with self.subTest(mutation=mutations.index(mutation)),self.assertRaises(Exception):self.verify()
            self.assertEqual(self.ledger.read(self.binding['nonce'])['state'],'ACCEPTED')

    def test_rollback_bytes_rejected_even_with_correct_artifact_digest(self):
        self.accept();url='/actions/artifacts/11/zip'
        with zipfile.ZipFile(io.BytesIO(self.api[url])) as z:files={n:z.read(n) for n in z.namelist()}
        path=next(p for p in files if p!='manifest.json');files[path]=b'wrong backup'
        self.api[url]=zipped(files)
        self.api['/actions/runs/123/artifacts?per_page=100']['artifacts'][1]['digest']='sha256:'+hashlib.sha256(self.api[url]).hexdigest()
        with self.assertRaisesRegex(ValueError,'Rollback bytes'):self.verify()


if __name__=='__main__':unittest.main()
