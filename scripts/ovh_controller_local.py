"""Explicit one-shot operator CLI. No daemon, automatic retries or setup.

Installing/configuring this proposal is a separate owner-authorized action.
Default invocation is disabled. No credentials are discovered from environment.
"""
import argparse
from dataclasses import fields
import hashlib
import json
import os
from pathlib import Path
import stat

from owner_bound_dispatch import GitHubDispatch, _attempt_once, reconcile
from owner_bound_dispatch_ledger import Ledger, binding_digest
from ovh_controller_audit import AuditAPI, AuditConfig, GitHubAudit
from ovh_controller_claim_adapter import OvhEvidence, RemoteLedger, RestoreSafeLedger
from ovh_controller_claim_ssh import SSHConfig, SSHTransport
from shared_ovh_controller_adapter import HASH, _json_bytes
from datetime import datetime, timezone

DENIED={'status':'DISABLED','dispatch_started':False,'retry_allowed':False,'production_accepted':False}
PATHS={'attempt_journal','read_token','audit_token','dispatch_token','claim_hmac'}


def private_path(value):
    if type(value) is not str or not Path(value).is_absolute() or str(Path(value).resolve())!=value:
        raise ValueError('Require absolute private path without symlinks')
    s=os.lstat(value)
    if (not stat.S_ISREG(s.st_mode) or stat.S_IMODE(s.st_mode)!=0o600
            or s.st_uid!=os.geteuid() or s.st_nlink!=1):
        raise ValueError('Private owner-only regular file required')
    return s


def private_bytes(path,limit):
    before=private_path(path)
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
    with os.fdopen(fd,'rb') as stream:
        opened=os.fstat(stream.fileno())
        if (opened.st_dev,opened.st_ino)!=(before.st_dev,before.st_ino):
            raise ValueError('Private file changed')
        raw=stream.read(limit+1)
    if not 0<len(raw)<=limit: raise ValueError('Invalid bounded private file')
    return raw


def token(path):
    raw=private_bytes(path,8192)
    if raw.endswith(b'\n'): raw=raw[:-1]
    text=raw.decode('ascii')
    if not text or any(ord(c)<33 or ord(c)>126 for c in text): raise ValueError('Invalid token file')
    return text


def configuration(path,pin):
    if type(pin) is not str or not HASH.fullmatch(pin): raise ValueError('Missing reviewed config digest')
    raw=private_bytes(path,65536)
    if hashlib.sha256(raw).hexdigest()!=pin: raise ValueError('Local config digest drift')
    cfg=_json_bytes(raw)
    if type(cfg) is not dict or set(cfg)!={'schema','issue_id','comment_id','audit','ssh','paths'} or type(cfg['schema']) is not int or cfg['schema']!=1:
        raise ValueError('Invalid exact local configuration')
    for key in ('issue_id','comment_id'):
        if type(cfg[key]) is not int or not 0<cfg[key]<=2**53-1: raise ValueError('Invalid owner event identity')
    if type(cfg['audit']) is not dict or set(cfg['audit'])!={f.name for f in fields(AuditConfig)}:
        raise ValueError('Invalid audit configuration')
    AuditConfig(**cfg['audit']).validate()
    if type(cfg['ssh']) is not dict or set(cfg['ssh'])!={f.name for f in fields(SSHConfig)}:
        raise ValueError('Invalid SSH configuration')
    ssh=SSHConfig(**cfg['ssh']);ssh.validate()
    if ssh.runtime_sha256 is None: raise ValueError('V1 requires the pinned private PHP runtime')
    paths=cfg['paths']
    if type(paths) is not dict or set(paths)!=PATHS: raise ValueError('Invalid credential/journal paths')
    all_paths=[*paths.values(),ssh.identity_file,path]
    if any(type(p) is not str for p in all_paths) or len(set(all_paths))!=len(all_paths):
        raise ValueError('Separate files required for each credential and journal')
    for p in all_paths: private_path(p)
    return cfg


def components(cfg,*,dispatch=False):
    paths=cfg['paths']
    reader=OvhEvidence(token(paths['read_token']))
    authority=GitHubAudit(AuditAPI(token(paths['audit_token'])),AuditConfig(**cfg['audit']),Ledger(paths['attempt_journal']))
    remote=RemoteLedger(SSHTransport(SSHConfig(**cfg['ssh'])),private_bytes(paths['claim_hmac'],1024))
    ledger=RestoreSafeLedger(remote,authority)
    sender=GitHubDispatch(token(paths['dispatch_token'])) if dispatch else None
    return reader,ledger,sender


def run(mode,cfg,*,nonce=None):
    if mode=='check': return {**DENIED,'status':'LOCAL_CONFIG_VALID_NO_NETWORK'}
    if mode=='review':
        reader=OvhEvidence(token(cfg['paths']['read_token']))
        binding=reader.prepare(cfg['issue_id'],cfg['comment_id'],datetime.now(timezone.utc))
        return {**DENIED,'status':'VERIFIED_READ_ONLY','binding_sha256':binding_digest(binding)}
    if mode=='attempt':
        reader,ledger,sender=components(cfg,dispatch=True)
        return _attempt_once(reader,ledger,sender,cfg['issue_id'],cfg['comment_id'])
    if mode=='reconcile':
        if type(nonce) is not str or not HASH.fullmatch(nonce): raise ValueError('Exact nonce required')
        reader,ledger,_=components(cfg)
        return reconcile(reader,ledger,nonce)
    raise ValueError('Unknown operation')


def main(argv=None):
    parser=argparse.ArgumentParser(description='One manually started owner-bound OVH controller attempt')
    parser.add_argument('mode',nargs='?',choices=('init-journal','check','review','attempt','reconcile'))
    parser.add_argument('--config')
    parser.add_argument('--config-sha256')
    parser.add_argument('--nonce')
    parser.add_argument('--journal')
    args=parser.parse_args(argv)
    if args.mode is None:
        print(json.dumps(DENIED,sort_keys=True));return 78
    try:
        if args.mode=='init-journal':
            if any(v is not None for v in (args.config,args.config_sha256,args.nonce)):
                raise ValueError('Journal initialization accepts no runtime inputs')
            path=Path(args.journal)
            parent=path.parent
            if (not path.is_absolute() or str(parent.resolve())!=str(parent)
                    or stat.S_IMODE(parent.stat().st_mode)!=0o700 or parent.stat().st_uid!=os.geteuid()):
                raise ValueError('Pre-existing private absolute directory required')
            Ledger.provision(path)
            for p in (path,parent):
                fd=os.open(p,os.O_RDONLY)
                try: os.fsync(fd)
                finally: os.close(fd)
            print(json.dumps({**DENIED,'status':'LOCAL_JOURNAL_CREATED_NO_NETWORK'},sort_keys=True));return 0
        if args.journal is not None: raise ValueError('Unexpected journal provisioning input')
        if args.nonce is not None and args.mode!='reconcile': raise ValueError('Unexpected nonce')
        cfg=configuration(args.config,args.config_sha256)
        result=run(args.mode,cfg,nonce=args.nonce)
        print(json.dumps(result,sort_keys=True))
        return 0 if result['status'] in ('LOCAL_CONFIG_VALID_NO_NETWORK','VERIFIED_READ_ONLY',
                                        'READ_ONLY_RECONCILIATION','ACCEPTED_RECONCILIATION_REQUIRED') else 75
    except Exception:
        print(json.dumps({**DENIED,'status':'STOP_NO_RETRY'},sort_keys=True));return 75


if __name__=='__main__': raise SystemExit(main())
