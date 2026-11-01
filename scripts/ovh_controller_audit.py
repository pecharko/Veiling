"""Dormant independent GitHub audit authority for one OVH dispatch attempt.

No repository/ruleset/credential provisioning or workflow integration. A private
audit repo is a PROPOSAL, not an existing or configured service. execute() denies.
"""
import base64
from dataclasses import dataclass
import hashlib
import json
import re
from urllib.request import Request, build_opener

from owner_bound_dispatch import NoRedirect
from owner_bound_dispatch_ledger import canonical, binding_digest
from ovh_controller_claim_adapter import AuditReservation, validate_binding
from shared_ovh_controller_adapter import HASH, SHA, _datetime, _json_bytes

REPOSITORY = 'pecharko/Veiling-release-audit'
BASE = 'https://api.github.com/repos/' + REPOSITORY
GRAPHQL = 'https://api.github.com/graphql'
MAX_REPLY = 256 * 1024
MAX_LEDGER = 128 * 1024
LEDGER = 'claims.json'
BOOTSTRAP = b'{"claims":[],"schema":1}\n'
GET_PATH = re.compile(r'^(?:|/git/ref/heads/main|/git/(?:commits|trees|blobs)/[0-9a-f]{40}|/rulesets/[1-9][0-9]*)$')
MUTATION = '''mutation ReserveOvhAudit($input: CreateCommitOnBranchInput!) {
  createCommitOnBranch(input: $input) { commit { oid } }
}'''


def _sha(value):
    if type(value) is not str or not SHA.fullmatch(value):
        raise ValueError('Invalid immutable Git object')
    return value


@dataclass(frozen=True)
class AuditConfig:
    repository_id: int
    anchor_sha: str
    ruleset_id: int
    policy_updated_at: str

    def validate(self):
        if any(type(n) is not int or n <= 0 for n in (self.repository_id, self.ruleset_id)):
            raise ValueError('Pin real audit repository/ruleset numeric IDs')
        _sha(self.anchor_sha)
        _datetime(self.policy_updated_at)


class AuditAPI:
    """Fixed audit-repo GETs and one fixed compare-and-set GraphQL mutation.

    Requires a separately provisioned audit-only credential. No environment
    discovery, token minting, admin endpoints, redirects, retry or error logging.
    """
    def __init__(self, token):
        if type(token) is not str or not token:
            raise ValueError('Missing separately provisioned audit-only credential')
        self._token = token

    def _request(self, url, payload=None):
        request = Request(url, data=None if payload is None else canonical(payload).encode(), headers={
            'Authorization': 'Bearer ' + self._token,
            'Accept': 'application/vnd.github+json', 'Content-Type': 'application/json',
            'X-GitHub-Api-Version': '2022-11-28',
        }, method='GET' if payload is None else 'POST')
        try:
            with build_opener(NoRedirect()).open(request, timeout=20) as response:
                if response.status != 200 or response.geturl() != url:
                    raise ValueError('Untrusted audit API response')
                raw = response.read(MAX_REPLY + 1)
            if len(raw) > MAX_REPLY:
                raise ValueError('Oversized audit response')
            return _json_bytes(raw)
        except Exception:
            raise RuntimeError('Audit API outcome unknown; STOP, NO RETRY') from None

    def get(self, path):
        if type(path) is not str or not GET_PATH.fullmatch(path):
            raise ValueError('API path outside fixed audit allowlist')
        return self._request(BASE + path)

    def commit_once(self, expected_head, ledger_bytes):
        _sha(expected_head)
        if type(ledger_bytes) is not bytes or not 0 < len(ledger_bytes) <= MAX_LEDGER:
            raise ValueError('Invalid bounded audit ledger')
        ledger = _json_bytes(ledger_bytes)
        if (type(ledger) is not dict or set(ledger) != {'schema', 'claims'}
                or type(ledger['schema']) is not int or ledger['schema'] != 1
                or type(ledger['claims']) is not list or len(ledger['claims']) != 1
                or canonical(ledger).encode() + b'\n' != ledger_bytes):
            raise ValueError('Mutation only accepts one exact canonical claim')
        validate_binding(ledger['claims'][0])
        payload = {'query': MUTATION, 'variables': {'input': {
            'branch': {'repositoryNameWithOwner': REPOSITORY, 'branchName': 'main'},
            'expectedHeadOid': expected_head,
            'message': {'headline': 'Reserve one owner-bound OVH dispatch authorization'},
            'fileChanges': {'additions': [{'path': LEDGER,
                'contents': base64.b64encode(ledger_bytes).decode('ascii')}]},
        }}}
        return self._request(GRAPHQL, payload)


class GitHubAudit:
    """Independent atomic, append-only one-shot record; no reset or unlock.

    Anchor must be a pinned root commit containing an empty canonical ledger.
    The only accepted successor contains one exact binding, parent=anchor.
    More history, deleted/cleared ledger or mismatched policy fails closed.
    This deliberately admits ONE global attempt, not automatic next releases.
    """
    def __init__(self, api, config):
        if type(config) is not AuditConfig:
            raise ValueError('Require exact reviewed audit baseline')
        config.validate()
        self.api, self.config = api, config
        self._uncertain = False

    def _guard(self, expected_owner=None):
        cfg = self.config
        repo = self.api.get('')
        if (type(repo) is not dict or type(repo.get('id')) is not int or repo['id'] != cfg.repository_id
                or repo.get('full_name') != REPOSITORY or repo.get('private') is not True
                or repo.get('fork') is not False or repo.get('archived') is not False
                or repo.get('disabled') is not False or repo.get('default_branch') != 'main'):
            raise ValueError('Audit repository identity/privacy drift')
        owner, permissions = repo.get('owner'), repo.get('permissions')
        if (type(owner) is not dict or owner.get('login') != 'pecharko' or owner.get('type') != 'User'
                or type(owner.get('id')) is not int or owner['id'] <= 0
                or expected_owner is not None and owner['id'] != expected_owner
                or type(permissions) is not dict or permissions.get('pull') is not True
                or permissions.get('push') is not True or permissions.get('admin') is not False
                or permissions.get('maintain') is not False):
            raise ValueError('Audit actor separation/effective repository permissions missing')
        rule = self.api.get('/rulesets/' + str(cfg.ruleset_id))
        if (type(rule) is not dict or type(rule.get('id')) is not int or rule['id'] != cfg.ruleset_id
                or rule.get('source_type') != 'Repository' or rule.get('source') != REPOSITORY
                or rule.get('target') != 'branch' or rule.get('enforcement') != 'active'
                or rule.get('bypass_actors') != []
                or _datetime(rule.get('updated_at')) != _datetime(cfg.policy_updated_at)
                or rule.get('conditions') != {'ref_name': {'include': ['refs/heads/main'], 'exclude': []}}):
            raise ValueError('Pinned audit protection/policy version not proven')
        rules = rule.get('rules')
        if (type(rules) is not list or len(rules) != 3
                or any(type(r) is not dict or set(r) != {'type'} for r in rules)
                or {r['type'] for r in rules} != {'deletion', 'non_fast_forward', 'required_linear_history'}):
            raise ValueError('Missing immutable linear-history rules')
        return owner['id']

    def _commit(self, sha):
        sha = _sha(sha)
        commit = self.api.get('/git/commits/' + sha)
        if (type(commit) is not dict or commit.get('sha') != sha
                or type(commit.get('tree')) is not dict or type(commit.get('parents')) is not list):
            raise ValueError('Missing immutable audit commit')
        _sha(commit['tree'].get('sha'))
        return commit

    def _ledger(self, commit):
        tree_sha = commit['tree']['sha']
        tree = self.api.get('/git/trees/' + tree_sha)
        if (type(tree) is not dict or tree.get('sha') != tree_sha or tree.get('truncated') is not False
                or type(tree.get('tree')) is not list or len(tree['tree']) != 1):
            raise ValueError('Incomplete immutable root tree')
        entries = [f for f in tree['tree'] if type(f) is dict and f.get('path') == LEDGER]
        if len(entries) != 1 or entries[0].get('mode') != '100644' or entries[0].get('type') != 'blob':
            raise ValueError('Missing/nonregular/duplicate audit ledger')
        oid = _sha(entries[0].get('sha'))
        blob = self.api.get('/git/blobs/' + oid)
        if (type(blob) is not dict or blob.get('sha') != oid or blob.get('encoding') != 'base64'
                or type(blob.get('size')) is not int or not 0 < blob['size'] <= MAX_LEDGER
                or type(blob.get('content')) is not str or len(blob['content']) > 2 * MAX_LEDGER):
            raise ValueError('Invalid immutable audit blob')
        raw = base64.b64decode(blob['content'].replace('\n', ''), validate=True)
        typed = b'blob ' + str(len(raw)).encode('ascii') + b'\0' + raw
        if len(raw) != blob['size'] or hashlib.sha1(typed).hexdigest() != oid:
            raise ValueError('Audit blob size/content hash mismatch')
        ledger = _json_bytes(raw)
        if (type(ledger) is not dict or set(ledger) != {'schema', 'claims'}
                or type(ledger['schema']) is not int or ledger['schema'] != 1
                or type(ledger['claims']) is not list or len(ledger['claims']) > 1
                or canonical(ledger).encode() + b'\n' != raw):
            raise ValueError('Invalid canonical one-shot ledger')
        return ledger

    def _snapshot(self, owner):
        anchor = self._commit(self.config.anchor_sha)
        if anchor['parents'] != [] or self._ledger(anchor) != {'schema': 1, 'claims': []}:
            raise ValueError('Anchor is not the reviewed empty root bootstrap')
        ref = self.api.get('/git/ref/heads/main')
        if (type(ref) is not dict or ref.get('ref') != 'refs/heads/main'
                or type(ref.get('object')) is not dict or ref['object'].get('type') != 'commit'):
            raise ValueError('Untrusted audit branch identity')
        head = _sha(ref['object'].get('sha'))
        if head == self.config.anchor_sha:
            return head, []
        commit = self._commit(head)
        parents = commit['parents']
        if len(parents) != 1 or type(parents[0]) is not dict or parents[0].get('sha') != self.config.anchor_sha:
            raise ValueError('Unexpected audit history; never fall back to an older good commit')
        claims = self._ledger(commit)['claims']
        if len(claims) != 1:
            raise ValueError('Cleared/nonappend audit history')
        validate_binding(claims[0])
        if claims[0]['owner_id'] != owner:
            raise ValueError('Stored binding owner mismatch')
        return head, claims

    def reserve_once(self, binding):
        validate_binding(binding)
        if self._uncertain:
            raise RuntimeError('Audit write uncertain; STOP, NO RETRY')
        attempted = False
        try:
            owner = self._guard(binding['owner_id'])
            head, claims = self._snapshot(owner)
            if claims:
                return None
            self._guard(owner)  # Fresh protection baseline immediately before mutation.
            raw = canonical({'schema': 1, 'claims': [binding]}).encode() + b'\n'
            attempted = True
            reply = self.api.commit_once(head, raw)
            if type(reply) is not dict or reply.get('errors'):
                raise ValueError('Uncertain/stale/failed compare-and-set mutation')
            oid = _sha(reply['data']['createCommitOnBranch']['commit']['oid'])
            owner = self._guard(owner)
            observed_head, observed = self._snapshot(owner)
            if observed_head != oid or observed != [binding]:
                raise ValueError('Independent audit readback differs from attempted binding')
            return AuditReservation(binding['nonce'], binding_digest(binding))
        except Exception:
            if attempted:
                self._uncertain = True
            raise RuntimeError('Audit reservation unproven; STOP, NO RETRY') from None

    def lookup(self, nonce):
        if type(nonce) is not str or not HASH.fullmatch(nonce):
            raise ValueError('Invalid audit nonce')
        try:
            owner = self._guard()
            _, claims = self._snapshot(owner)
            if len(claims) != 1 or claims[0]['nonce'] != nonce:
                raise ValueError('No immutable exact audit binding')
            return claims[0]
        except Exception:
            # Absence is NEVER permission to repeat an uncertain mutation.
            raise RuntimeError('Audit evidence unavailable; STOP, NO RETRY') from None


def execute(*_args, **_kwargs):
    return {'status': 'DISABLED', 'dispatch_started': False,
            'write_allowed': False, 'retry_allowed': False}
