"""Explicit Mac-ledger upgrade and owner-accepted completion. Never dispatches.

Completed nonces/consents/releases remain unique forever. Completion is manual,
requires independent GitHub evidence and a fresh personal owner acceptance,
and never permits retry of the completed authorization. No automatic migration.
"""
from contextlib import contextmanager
from dataclasses import dataclass
from datetime import datetime, timezone
import hashlib
import io
import json
import os
from pathlib import Path
import sqlite3
import stat
import zipfile

from owner_bound_dispatch import GitHubEvidence, _repo_run
from owner_bound_dispatch_ledger import Ledger, SCHEMA, canonical, binding_digest
from ovh_controller_claim_adapter import validate_binding
import shared_ovh_controller_evidence as api
from shared_ovh_controller_adapter import _datetime, _require_fresh, _json_bytes
from ovh_release_evidence_discovery import read_evidence_zip
from ovh_release_evidence_review import verify as verify_release

V2_TABLE = """CREATE TABLE claims (
 nonce TEXT PRIMARY KEY, authorization TEXT NOT NULL UNIQUE,
 release_id TEXT NOT NULL UNIQUE, binding TEXT NOT NULL,
 state TEXT NOT NULL CHECK(state IN ('RESERVED','ATTEMPTED','ACCEPTED','UNKNOWN','ABORTED','COMPLETED')),
 run_id INTEGER, completion TEXT,
 CHECK((state='COMPLETED') = (completion IS NOT NULL)),
 CHECK(state!='COMPLETED' OR run_id IS NOT NULL)
)"""
V2_INDEX = "CREATE UNIQUE INDEX one_active_dispatch ON claims((1)) WHERE state NOT IN ('ABORTED','COMPLETED')"


@dataclass(frozen=True)
class VerifiedCompletion:
    nonce: str
    binding_sha256: str
    run_id: int
    receipt: str


class MacReleaseLedger(Ledger):
    def __init__(self, path):
        super().__init__(path)
        with self._connect() as db:
            if db.execute('PRAGMA user_version').fetchone()[0] != 2:
                raise ValueError('Explicit private journal upgrade required')
            if db.execute("SELECT sql FROM sqlite_master WHERE type='table' AND name='claims'").fetchone()[0] != V2_TABLE:
                raise ValueError('Unexpected journal schema')
            if db.execute("SELECT sql FROM sqlite_master WHERE type='index' AND name='one_active_dispatch'").fetchone()[0] != V2_INDEX:
                raise ValueError('Unexpected active-slot constraint')

    @contextmanager
    def _connect(self):
        with super()._connect() as db:
            db.execute('PRAGMA fullfsync=ON')
            yield db

    @classmethod
    def provision(cls, path):
        path = Path(path)
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        os.close(fd)
        with sqlite3.connect(str(path), isolation_level=None) as db:
            db.execute('PRAGMA synchronous=FULL'); db.execute('PRAGMA fullfsync=ON')
            db.execute('BEGIN IMMEDIATE')
            db.execute(V2_TABLE); db.execute(V2_INDEX); db.execute('PRAGMA user_version=2')
            db.execute('COMMIT')
        return cls(path)

    @classmethod
    def upgrade(cls, path):
        """Explicit local-only v1→v2, retaining every row and a private backup.

        Never retries DDL, overwrites a backup, deletes a claim or opens HTTP.
        A separate read-only source connection backs up under the write lock.
        """
        old = Ledger(path)
        backup = Path(str(old.path) + '.v1-backup.sqlite')
        with old._connect() as db:
            db.execute('PRAGMA fullfsync=ON'); db.execute('BEGIN IMMEDIATE')
            try:
                if db.execute('PRAGMA user_version').fetchone()[0] != 0:
                    raise ValueError('Only the exact legacy journal may be upgraded once')
                expected = [s.strip() for s in SCHEMA.split(';') if s.strip()]
                if db.execute("SELECT sql FROM sqlite_master WHERE type='table' AND name='claims'").fetchone()[0] != expected[0]:
                    raise ValueError('Unexpected legacy table')
                if db.execute("SELECT sql FROM sqlite_master WHERE name='one_active_dispatch'").fetchone()[0] != expected[1]:
                    raise ValueError('Unexpected legacy active constraint')
                before = db.execute('SELECT * FROM claims ORDER BY nonce').fetchall()
                fd = os.open(backup, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600); os.close(fd)
                with sqlite3.connect(old.path.resolve().as_uri()+'?mode=ro',uri=True) as source, sqlite3.connect(str(backup)) as target:
                    source.backup(target)
                cls._sync(backup)
                db.execute('ALTER TABLE claims RENAME TO claims_v1')
                db.execute('DROP INDEX one_active_dispatch')
                db.execute(V2_TABLE); db.execute(V2_INDEX)
                db.execute('INSERT INTO claims(nonce,authorization,release_id,binding,state,run_id) SELECT * FROM claims_v1')
                if db.execute('SELECT nonce,authorization,release_id,binding,state,run_id FROM claims ORDER BY nonce').fetchall() != before:
                    raise ValueError('Migration changed legacy claims')
                db.execute('DROP TABLE claims_v1'); db.execute('PRAGMA user_version=2')
                db.execute('COMMIT')
            except Exception:
                if db.in_transaction: db.execute('ROLLBACK')
                raise
        cls._sync(old.path)
        return backup

    @staticmethod
    def _sync(path):
        for p in (Path(path), Path(path).parent):
            fd=os.open(p,os.O_RDONLY)
            try: os.fsync(fd)
            finally: os.close(fd)

    def reserve(self, binding):
        validate_binding(binding)
        with self._connect() as db:
            try:
                db.execute('BEGIN IMMEDIATE')
                db.execute('INSERT INTO claims VALUES (?,?,?,?,?,NULL,NULL)', (
                    binding['nonce'],canonical([binding['repository'],binding['issue_id'],binding['comment_id']]),
                    binding['release_id'],canonical(binding),'RESERVED'))
                db.execute('COMMIT'); return True
            except sqlite3.IntegrityError:
                if db.in_transaction: db.execute('ROLLBACK')
                return False

    def read(self, nonce):
        with self._connect() as db:
            row=db.execute('SELECT nonce,authorization,release_id,binding,state,run_id,completion FROM claims WHERE nonce=?',(nonce,)).fetchone()
        if row is None: raise ValueError('Missing claim; never permission to retry')
        binding=json.loads(row[3]); validate_binding(binding)
        if (binding['nonce']!=row[0] or binding['release_id']!=row[2]
                or canonical([binding['repository'],binding['issue_id'],binding['comment_id']])!=row[1]):
            raise ValueError('Stored identity mismatch')
        return {'binding':binding,'state':row[4],'run_id':row[5],
                'completion':None if row[6] is None else json.loads(row[6])}

    def complete(self, binding, proof):
        validate_binding(binding)
        if (type(proof) is not VerifiedCompletion or proof.nonce!=binding['nonce']
                or proof.binding_sha256!=binding_digest(binding) or type(proof.run_id) is not int or proof.run_id<=0):
            raise ValueError('Independent typed completion proof required')
        receipt=json.loads(proof.receipt)
        if (receipt.get('nonce')!=binding['nonce'] or receipt.get('binding_sha256')!=binding_digest(binding)
                or receipt.get('run_id')!=proof.run_id): raise ValueError('Completion proof differs')
        with self._connect() as db:
            db.execute('BEGIN IMMEDIATE')
            changed=db.execute("UPDATE claims SET state='COMPLETED',run_id=?,completion=? WHERE nonce=? AND binding=? AND state='ACCEPTED' AND completion IS NULL",
                               (proof.run_id,proof.receipt,binding['nonce'],canonical(binding))).rowcount
            if changed!=1:
                db.execute('ROLLBACK'); raise ValueError('Only a fresh accepted claim can be completed')
            db.execute('COMMIT')


def completion_text(binding, run_id):
    return '\n'.join(['ACCEPT MAC RELEASE WITH READBACK','nonce='+binding['nonce'],
        'release_id='+binding['release_id'],'release_sha='+binding['main_sha'],
        'run_id='+str(run_id),'acceptance=READBACK-AND-BROWSER-ACCEPTED'])


def _zip_members(raw, expected):
    if type(raw) is not bytes or not 0<len(raw)<=2*1024*1024: raise ValueError('Invalid proof archive')
    with zipfile.ZipFile(io.BytesIO(raw)) as z:
        members=z.infolist()
        if len(members)!=len(expected) or {m.filename for m in members}!=set(expected):
            raise ValueError('Unexpected/duplicate proof members')
        if (sum(m.file_size for m in members)>16*1024*1024
                or any(m.is_dir() or stat.S_ISLNK(m.external_attr>>16) or m.file_size<=0 or m.file_size>8*1024*1024 for m in members)):
            raise ValueError('Unsafe proof member')
        return {m.filename:z.read(m.filename) for m in members}


def verify_completion(reader, ledger, nonce, run_id, acceptance_comment_id, *, now=None):
    """GET-only proof. Owner acceptance is independently retrieved, never a flag."""
    if not isinstance(reader, GitHubEvidence): raise ValueError('Trusted GET reader required')
    if any(type(n) is not int or not 0<n<=2**53-1 for n in (run_id,acceptance_comment_id)):
        raise ValueError('Exact run and personal acceptance-comment IDs required')
    now=now or datetime.now(timezone.utc)
    record=ledger.read(nonce); binding=record['binding']
    if record['state']!='ACCEPTED': raise ValueError('Uncertain/unattempted/completed authorization cannot be unlocked')
    repo=reader.get(''); owner=repo.get('owner',{})
    if (repo.get('full_name')!='pecharko/Veiling' or owner.get('login')!='pecharko'
            or owner.get('type')!='User' or owner.get('id')!=binding['owner_id']): raise ValueError('Owner drift')
    issue=reader.get('/issues/'+str(binding['issue_id']))
    original=reader.get('/issues/comments/'+str(binding['comment_id']))
    acceptance=reader.get('/issues/comments/'+str(acceptance_comment_id))
    if issue.get('title')!='DEPLOY-AUTH: shared-ovh-release/'+binding['release_id'] or issue.get('pull_request') is not None:
        raise ValueError('Wrong original owner issue')
    for obj in (issue,original,acceptance):
        user=obj.get('user',{})
        if user.get('id')!=binding['owner_id'] or user.get('type')!='User': raise ValueError('Not a personal owner acceptance')
    for comment in (original,acceptance):
        if comment.get('created_at')!=comment.get('updated_at') or comment.get('issue_url')!=api.BASE+'/issues/'+str(binding['issue_id']):
            raise ValueError('Edited or unrelated owner comment')
    if (original.get('id')!=binding['comment_id'] or type(original.get('body')) is not str
            or hashlib.sha256(original['body'].encode()).hexdigest()!=binding['approval_sha256']
            or acceptance.get('id')!=acceptance_comment_id or acceptance.get('body')!=completion_text(binding,run_id)):
        raise ValueError('Owner acceptance does not bind exact original authorization/run')
    created=_datetime(acceptance.get('created_at')); _require_fresh(created,now,'Owner completion acceptance')
    run=reader.get('/actions/runs/'+str(run_id))
    _repo_run(run,binding['main_sha'],workflow='.github/workflows/shared-ovh-release.yml',event='workflow_dispatch')
    if run.get('id')!=run_id or run.get('status')!='completed' or run.get('conclusion')!='success' or _datetime(run.get('updated_at'))>created:
        raise ValueError('No successful completed run before owner acceptance')
    candidates=reader.runs('/actions/workflows/shared-ovh-release.yml/runs?per_page=100')
    matches=[r for r in candidates if type(r) is dict and r.get('head_sha')==binding['main_sha']]
    if len(matches)!=1 or matches[0].get('id')!=run_id: raise ValueError('Ambiguous run identity')
    artifacts=api._list(reader.token,'/actions/runs/'+str(run_id)+'/artifacts?per_page=100','artifacts')
    raw_manifest=api._git_file(reader.token,'releases/'+binding['release_id']+'.json',binding['main_sha'])
    manifest=json.loads(raw_manifest)
    if hashlib.sha256(raw_manifest).hexdigest()!=binding['manifest_sha256'] or manifest.get('files')!=binding['files']:
        raise ValueError('Immutable manifest/binding drift')
    archives={}; digests={}
    for kind in ('evidence','rollback'):
        name='shared-ovh-'+kind+'-'+str(run_id)
        selected=[a for a in artifacts if a.get('name')==name and a.get('expired') is False]
        if len(selected)!=1: raise ValueError('Missing/duplicate proof artifact')
        a=selected[0]
        if (type(a.get('id')) is not int or a['id']<=0 or a.get('workflow_run',{}).get('id')!=run_id
                or a.get('workflow_run',{}).get('head_sha')!=binding['main_sha']
                or _datetime(a.get('expires_at'))<=now): raise ValueError('Unbound/expired artifact')
        blob=api.github_get(api.BASE+'/actions/artifacts/'+str(a['id'])+'/zip',reader.token)
        digest='sha256:'+hashlib.sha256(blob).hexdigest()
        if a.get('digest')!=digest: raise ValueError('Artifact digest mismatch')
        archives[kind]=blob; digests[kind]={'id':a['id'],'digest':digest}
    evidence=read_evidence_zip(archives['evidence'])
    if evidence.get('release_id')!=binding['release_id']: raise ValueError('Evidence release mismatch')
    verify_release(run,evidence,manifest,artifacts)
    prior={r['path']:r['remote_sha256'] for r in binding['files']}
    source={r['path']:r['source_sha256'] for r in binding['files']}
    backup=_zip_members(archives['rollback'],{'manifest.json',*[p for p,h in prior.items() if h!='ABSENT']})
    metadata=_json_bytes(backup['manifest.json'])
    if metadata!={'release_id':binding['release_id'],'release_sha':binding['main_sha'],'previous':prior,'source':source}:
        raise ValueError('Rollback manifest mismatch')
    if any(hashlib.sha256(backup[p]).hexdigest()!=h for p,h in prior.items() if h!='ABSENT'):
        raise ValueError('Rollback bytes mismatch')
    if (reader.get('/issues/comments/'+str(acceptance_comment_id))!=acceptance
            or reader.get('/issues/comments/'+str(binding['comment_id']))!=original):
        raise ValueError('Owner comments changed during verification')
    receipt=canonical({'nonce':nonce,'binding_sha256':binding_digest(binding),'run_id':run_id,
        'acceptance_comment_id':acceptance_comment_id,'acceptance_sha256':hashlib.sha256(acceptance['body'].encode()).hexdigest(),
        'artifacts':digests,'verified_at':now.isoformat()})
    return VerifiedCompletion(nonce,binding_digest(binding),run_id,receipt)
