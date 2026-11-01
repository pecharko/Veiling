"""Dormant OVH claim-store protocol; no HTTP, SFTP, credentials or provisioning.

The transport and independent anti-rollback authority MUST be provided by a
separately reviewed private runtime. Nothing here selects or activates one.
"""
from dataclasses import dataclass
import hashlib
import hmac
import secrets
import time

from controller_cron_handoff_contract import MAX_TTL
from controller_one_shot_claim_contract import claim_key
from owner_bound_dispatch_ledger import canonical, binding_digest
from owner_bound_dispatch import GitHubEvidence, _repo_run
from shared_ovh_controller_adapter import (
    HASH, _json_bytes, _validate_manifest, _datetime, _require_fresh,
)

REQUEST_DOMAIN = b'ovh-controller-claims/request/v1\n'
RESPONSE_DOMAIN = b'ovh-controller-claims/response/v1\n'
FIELDS = {'repository', 'owner_id', 'issue_id', 'comment_id', 'nonce', 'release_id',
          'main_sha', 'manifest_sha256', 'approval_sha256', 'files', 'push_ci_run_ids'}
STATES = {'RESERVED', 'ATTEMPTED', 'ACCEPTED', 'UNKNOWN', 'ABORTED'}
TRANSITIONS = {('RESERVED', 'ATTEMPTED'), ('RESERVED', 'ABORTED'),
               ('ATTEMPTED', 'ACCEPTED'), ('ATTEMPTED', 'UNKNOWN')}
MAX_WIRE = 128 * 1024
MYSQL_CI = '.github/workflows/controller-claims-mysql-integration.yml'


class OvhEvidence(GitHubEvidence):
    """Retain existing owner/release gates and bind independent MySQL push-CI."""
    def prepare(self, issue_id, comment_id, now):
        binding = super().prepare(issue_id, comment_id, now)
        sha = binding['main_sha']
        runs = self.runs('/actions/runs?event=push&branch=main&head_sha=' + sha + '&per_page=100')
        matches = [r for r in runs if isinstance(r, dict) and r.get('path') == MYSQL_CI]
        if not matches:
            raise ValueError('Missing independent OVH MySQL push CI')
        run = max(matches, key=lambda r: r['id'])
        _repo_run(run, sha, workflow=MYSQL_CI, event='push')
        if run.get('status') != 'completed' or run.get('conclusion') != 'success':
            raise ValueError('Latest OVH MySQL push CI not successful')
        completed = _datetime(run.get('updated_at'))
        _require_fresh(completed, now, 'Independent OVH MySQL push CI')
        comment = self.get('/issues/comments/' + str(comment_id))
        if (comment.get('id') != comment_id
                or comment.get('user', {}).get('id') != binding['owner_id']
                or type(comment.get('body')) is not str
                or hashlib.sha256(comment['body'].encode()).hexdigest() != binding['approval_sha256']
                or comment.get('updated_at') != comment.get('created_at')
                or completed > _datetime(comment.get('created_at'))
                or self.get('/branches/main').get('commit', {}).get('sha') != sha):
            raise ValueError('Owner consent/main drifted or predates OVH MySQL CI')
        if run['id'] in binding['push_ci_run_ids']:
            raise ValueError('Independent MySQL CI run identity collision')
        return {**binding, 'push_ci_run_ids': [*binding['push_ci_run_ids'], run['id']]}


def validate_binding(binding):
    if type(binding) is not dict or set(binding) != FIELDS:
        raise ValueError('Invalid exact binding')
    claim_key(binding['repository'], binding['issue_id'], binding['comment_id'],
              binding['release_id'], binding['main_sha'])
    for value in (binding['owner_id'], binding['issue_id'], binding['comment_id']):
        if type(value) is not int or not 0 < value <= 2**53 - 1:
            raise ValueError('Invalid account/event identity')
    for key in ('nonce', 'manifest_sha256', 'approval_sha256'):
        if type(binding[key]) is not str or not HASH.fullmatch(binding[key]):
            raise ValueError('Invalid digest/nonce')
    ci = binding['push_ci_run_ids']
    if (type(ci) is not list or len(ci) != 3
            or any(type(n) is not int or not 0 < n <= 2**53 - 1 for n in ci)
            or len(set(ci)) != 3):
        raise ValueError('Invalid independent push-CI identities')
    _validate_manifest({'schema': 1, 'files': binding['files']},
                       {'files': binding['files']})
    if len(canonical(binding).encode()) > MAX_WIRE // 2:
        raise ValueError('Binding exceeds protocol bound')
    return binding


def mac(secret, domain, raw):
    if type(secret) is not bytes or not 32 <= len(secret) <= 1024:
        raise ValueError('Missing separately provisioned signing credential')
    return hmac.new(secret, domain + raw, hashlib.sha256).hexdigest()


class RemoteLedger:
    """Single exchange per operation; ambiguous output is never retried.

    No concrete external transport is installed. exchange(raw, mac) must use
    the separately authenticated private OVH execution path, not public input.
    """
    def __init__(self, transport, secret, *, clock=None):
        mac(secret, REQUEST_DOMAIN, b'')
        self.transport = transport
        self.secret = secret
        self.clock = clock or (lambda: int(time.time()))

    def _call(self, op, binding, old=None, new=None):
        validate_binding(binding)
        now = self.clock()
        if type(now) is not int or now <= 0:
            raise ValueError('Require integer protocol time')
        if op not in ('reserve', 'transition', 'read'):
            raise ValueError('Unknown claim operation')
        if op == 'transition' and (old, new) not in TRANSITIONS:
            raise ValueError('Forbidden transition')
        request = {'schema': 1, 'operation': op, 'binding': binding,
                   'issued_at': now, 'expires_at': now + MAX_TTL,
                   'request_nonce': secrets.token_hex(32), 'from_state': old, 'to_state': new}
        raw = canonical(request).encode()
        try:
            body, signature = self.transport.exchange(raw, mac(self.secret, REQUEST_DOMAIN, raw))
            if (type(body) is not bytes or not 0 < len(body) <= MAX_WIRE
                    or type(signature) is not str or not HASH.fullmatch(signature)
                    or not hmac.compare_digest(signature, mac(self.secret, RESPONSE_DOMAIN, body))):
                raise ValueError('Unauthenticated claim response')
            response = _json_bytes(body)
            if (type(response) is not dict or set(response) != {
                    'schema', 'operation', 'request_nonce', 'binding_sha256', 'outcome', 'record'}
                    or type(response['schema']) is not int or response['schema'] != 1
                    or response['operation'] != op
                    or response['request_nonce'] != request['request_nonce']
                    or response['binding_sha256'] != binding_digest(binding)):
                raise ValueError('Unbound claim response')
            return response
        except Exception:
            raise RuntimeError('Claim outcome unknown; STOP, NO RETRY') from None

    def reserve(self, binding):
        result = self._call('reserve', binding)
        if result['record'] is not None or result['outcome'] not in ('RESERVED', 'CONFLICT'):
            raise RuntimeError('Reservation unknown; STOP, NO RETRY')
        return result['outcome'] == 'RESERVED'

    def transition(self, binding, old, new):
        result = self._call('transition', binding, old, new)
        if result['outcome'] != new or result['record'] is not None:
            raise RuntimeError('Transition unknown; STOP, NO RETRY')

    def read_bound(self, binding):
        result = self._call('read', binding)
        record = result['record']
        if (result['outcome'] != 'RECORD' or type(record) is not dict
                or set(record) != {'binding', 'state'}
                or record['binding'] != binding or type(record['state']) is not str
                or record['state'] not in STATES):
            raise RuntimeError('Claim missing/mismatched; STOP, NO RETRY')
        return {**record, 'run_id': None}


@dataclass(frozen=True)
class AuditReservation:
    nonce: str
    binding_sha256: str


class RestoreSafeLedger:
    """Keep the existing dispatch-ledger interface, with external CAS FIRST.

    audit.reserve_once(binding) must atomically consume nonce, consent, release
    and active-dispatch scope in storage INDEPENDENT of OVH backups/restore.
    It returns a typed new-reservation receipt or None for conflicts. It must
    never return a receipt recovered by reading an earlier reservation.
    lookup(nonce) returns its immutable exact binding; unknown/unavailable blocks.
    No authority is inferred from a JSON proof flag, SQL count or local file.
    No audit provider is selected/configured here; no permissive fallback.
    """
    def __init__(self, remote, audit):
        self.remote = remote
        self.audit = audit

    def reserve(self, binding):
        validate_binding(binding)
        if self.audit is None:
            raise RuntimeError('Independent restore-safe authority unavailable')
        receipt = self.audit.reserve_once(binding)
        if receipt is None:
            return False
        if (type(receipt) is not AuditReservation or receipt.nonce != binding['nonce']
                or receipt.binding_sha256 != binding_digest(binding)):
            raise RuntimeError('External reservation unproven; STOP, NO RETRY')
        self._verify(binding)
        return self.remote.reserve(binding)

    def _verify(self, binding):
        if self.audit is None or self.audit.lookup(binding['nonce']) != binding:
            raise RuntimeError('Independent claim absent/drifted; STOP, NO RETRY')

    def transition(self, binding, old, new):
        validate_binding(binding)
        self._verify(binding)
        return self.remote.transition(binding, old, new)

    def read(self, nonce):
        if self.audit is None:
            raise RuntimeError('Independent claim unavailable')
        binding = self.audit.lookup(nonce)
        validate_binding(binding)
        if binding['nonce'] != nonce:
            raise RuntimeError('Independent nonce mismatch')
        return self.remote.read_bound(binding)


def execute(*_args, **_kwargs):
    return {'status': 'DISABLED', 'dispatch_started': False,
            'write_allowed': False, 'retry_allowed': False}
