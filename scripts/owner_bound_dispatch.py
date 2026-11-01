"""Dormant owner-bound Shared OVH dispatch integration.

No workflow imports this module. execute() is unconditionally disabled in this
reviewed revision. The private orchestration seam is exercised with fake I/O.
Activation requires a separately reviewed service, persistent ledger and rights.
"""
from datetime import datetime, timezone
import hashlib
import json
from urllib.request import Request, HTTPRedirectHandler, build_opener

import shared_ovh_controller_evidence as evidence
from shared_ovh_controller_adapter import (
    REPO, WORKFLOW, SHA, parse_approval, _datetime, _require_fresh,
)
from owner_bound_dispatch_ledger import binding_digest

CI_PATHS = frozenset(('.github/workflows/php-lint.yml',
                      '.github/workflows/owner-bound-dispatch-offline-tests.yml'))
DENIED = {'status': 'DISABLED', 'dispatch_started': False,
          'retry_allowed': False, 'production_accepted': False}


def execute(*_args, **_kwargs):
    """No JSON flag, environment variable or CLI can activate this revision."""
    return dict(DENIED)


def _repo_run(run, sha, *, workflow=None, event=None):
    if (not isinstance(run, dict) or type(run.get('id')) is not int or run['id'] <= 0
            or run.get('head_sha') != sha or run.get('head_branch') != 'main'
            or run.get('repository', {}).get('full_name') != REPO
            or run.get('head_repository', {}).get('full_name') != REPO
            or type(run.get('run_attempt')) is not int or run['run_attempt'] != 1
            or workflow is not None and run.get('path') != workflow
            or event is not None and run.get('event') != event):
        raise ValueError('Run identity, attempt or provenance mismatch')


class GitHubEvidence:
    """Read-only independent GETs; never accept caller-supplied proof booleans."""
    def __init__(self, read_token):
        self.token = read_token

    def get(self, path):
        if path == '':
            return json.loads(evidence.github_get(evidence.BASE, self.token))
        return evidence._api_json(self.token, path)

    def runs(self, path):
        # Bounded inventories fail closed instead of silently skipping a page.
        return evidence._list(self.token, path, 'workflow_runs')

    def prepare(self, issue_id, comment_id, now):
        evidence._positive(issue_id, 'issue ID')
        evidence._positive(comment_id, 'comment ID')
        repository = self.get('')
        owner = repository.get('owner', {})
        if (repository.get('full_name') != REPO or owner.get('login') != 'pecharko'
                or owner.get('type') != 'User' or type(owner.get('id')) is not int
                or owner['id'] <= 0):
            raise ValueError('Independent repository owner identity missing')
        comment = self.get('/issues/comments/' + str(comment_id))
        issue = self.get('/issues/' + str(issue_id))
        for user in (comment.get('user', {}), issue.get('user', {})):
            if user.get('id') != owner['id'] or user.get('type') != 'User':
                raise ValueError('Owner account ID mismatch')
        approval = parse_approval(comment.get('body'))
        if ('nonce' not in approval or comment.get('id') != comment_id
                or issue.get('number') != issue_id):
            raise ValueError('Missing owner-approved random one-use nonce')
        # Reuse immutable manifest, per-file source/remote digests, owner-event,
        # approval age/history and authentic read-only preflight verification.
        review = evidence.collect_review(self.token, issue_id, comment_id, now=now)
        if (review.get('status') != 'OFFLINE_CONTRACT_VERIFIED'
                or review['release_sha'] != approval['release_sha']
                or review['manifest_sha256'] != approval['manifest_sha256']):
            raise ValueError('Release evidence mismatch')
        # Bind the exact comment snapshot read by the reusable evidence reader.
        if (self.get('/issues/comments/' + str(comment_id)) != comment
                or self.get('/issues/' + str(issue_id)) != issue):
            raise ValueError('Owner consent changed during verification')
        sha = review['release_sha']
        ci = self.runs('/actions/runs?event=push&branch=main&head_sha=' + sha + '&per_page=100')
        selected = []
        for path in sorted(CI_PATHS):
            matches = [r for r in ci if isinstance(r, dict) and r.get('path') == path]
            if not matches:
                raise ValueError('Required independent main-push CI missing')
            run = max(matches, key=lambda r: r['id'])
            _repo_run(run, sha, workflow=path, event='push')
            if run.get('status') != 'completed' or run.get('conclusion') != 'success':
                raise ValueError('Latest required main-push CI not successful')
            completed = _datetime(run.get('updated_at'))
            _require_fresh(completed, now, 'Independent push CI')
            if completed > _datetime(comment['created_at']):
                raise ValueError('Owner approval predates successful push CI')
            selected.append(run['id'])
        # No previous dispatch at this immutable main, and no active target run.
        prior = self.runs('/actions/workflows/' + WORKFLOW + '/runs?per_page=100')
        if any(not isinstance(r, dict) or r.get('head_sha') == sha
               or r.get('status') != 'completed' for r in prior):
            raise ValueError('Previous or concurrent target workflow run')
        # Other production workflows share the OVH concurrency group. Treat
        # every active manual workflow as a blocker, including waiting gates.
        for status in ('queued', 'in_progress', 'waiting', 'pending', 'requested'):
            active = self.runs('/actions/runs?status=' + status + '&per_page=100')
            if any(not isinstance(r, dict) or r.get('event') == 'workflow_dispatch'
                   for r in active):
                raise ValueError('Concurrent manual workflow requires review')
        if self.get('/branches/main').get('commit', {}).get('sha') != sha:
            raise ValueError('Main drift immediately before reservation/attempt')
        return {
            'repository': REPO, 'owner_id': owner['id'], 'issue_id': issue_id,
            'comment_id': comment_id, 'nonce': approval['nonce'],
            'release_id': review['release_id'], 'main_sha': sha,
            'manifest_sha256': review['manifest_sha256'],
            'approval_sha256': hashlib.sha256(comment['body'].encode()).hexdigest(),
            'files': approval['files'], 'push_ci_run_ids': selected,
        }


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *_args, **_kwargs):
        raise ValueError('Dispatch redirect forbidden')


class GitHubDispatch:
    """One fixed HTTPS POST, no redirect, no retry and no response-body logging."""
    def __init__(self, token):
        if type(token) is not str or not token:
            raise ValueError('Missing separately provisioned dispatch credential')
        self._token = token

    def send_once(self, binding):
        if binding.get('repository') != REPO or not SHA.fullmatch(binding['main_sha']):
            raise ValueError('Invalid dispatch binding')
        # Revalidate release ID before constructing the exact protected inputs.
        from shared_ovh_controller_adapter import RELEASE_ID
        rid, sha = binding['release_id'], binding['main_sha']
        if not RELEASE_ID.fullmatch(rid):
            raise ValueError('Invalid release ID')
        payload = {'ref': 'main', 'inputs': {
            'release_id': rid, 'confirmation': 'DEPLOY-' + rid + '-' + sha}}
        url = evidence.BASE + '/actions/workflows/' + WORKFLOW + '/dispatches'
        request = Request(url, data=json.dumps(payload).encode(), headers={
            'Authorization': 'Bearer ' + self._token,
            'Accept': 'application/vnd.github+json',
            'Content-Type': 'application/json', 'X-GitHub-Api-Version': '2022-11-28',
        }, method='POST')
        with build_opener(NoRedirect()).open(request, timeout=20) as response:
            if response.status != 204 or response.geturl() != url:
                raise ValueError('Uncertain dispatch response')
        # 204 means accepted, never an independent run ID or deployment proof.


def _attempt_once(reader, ledger, sender, issue_id, comment_id, *, clock=None):
    """Internal proposal seam; not wired to a live workflow or public execute."""
    clock = clock or (lambda: datetime.now(timezone.utc))
    result = {**DENIED, 'status': 'BLOCKED'}
    try:
        binding = reader.prepare(issue_id, comment_id, clock())
        if ledger.reserve(binding) is not True:
            return {**result, 'status': 'REPLAY_OR_ACTIVE_CLAIM'}
    except Exception:
        return {**result, 'status': 'EVIDENCE_OR_RESERVATION_UNKNOWN_STOP_NO_RETRY'}
    result['binding_sha256'] = binding_digest(binding)
    try:
        if reader.prepare(issue_id, comment_id, clock()) != binding:
            raise ValueError('Binding drift after reservation')
    except Exception:
        # Reservation is retained, including after verification failures.
        try:
            ledger.transition(binding, 'RESERVED', 'ABORTED')
        except Exception:
            pass
        return {**result, 'status': 'REVALIDATION_STOP_NO_RETRY'}
    try:
        # Durable attempt marker BEFORE calling the external transport. A crash
        # here burns the nonce even if the POST was never sent.
        ledger.transition(binding, 'RESERVED', 'ATTEMPTED')
    except Exception:
        return {**result, 'status': 'ATTEMPT_MARKER_UNKNOWN_STOP_NO_RETRY'}
    try:
        sender.send_once(binding)
    except Exception:
        try:
            ledger.transition(binding, 'ATTEMPTED', 'UNKNOWN')
        except Exception:
            pass
        return {**result, 'status': 'DISPATCH_UNKNOWN_STOP_NO_RETRY',
                'dispatch_started': True}
    try:
        ledger.transition(binding, 'ATTEMPTED', 'ACCEPTED')
    except Exception:
        return {**result, 'status': 'DISPATCH_UNKNOWN_STOP_NO_RETRY',
                'dispatch_started': True}
    return {**result, 'status': 'ACCEPTED_RECONCILIATION_REQUIRED',
            'dispatch_started': True}


def reconcile(reader, ledger, nonce):
    """GET-only discovery. Candidates never grant retry or release acceptance.

    GitHub's existing target has no nonce/run-ID echo. Even one matching run is
    only a candidate, not proof that this POST created it. Retain the active
    safety lock until a separately authorized independent operator audit.
    """
    record = ledger.read(nonce)
    binding = record['binding']
    runs = reader.runs('/actions/workflows/' + WORKFLOW + '/runs?per_page=100')
    candidates = []
    for run in runs:
        if isinstance(run, dict) and run.get('head_sha') == binding['main_sha']:
            _repo_run(run, binding['main_sha'],
                      workflow='.github/workflows/' + WORKFLOW, event='workflow_dispatch')
            candidates.append(run['id'])
    return {**DENIED, 'status': 'READ_ONLY_RECONCILIATION',
            'claim_state': record['state'], 'candidate_run_ids': candidates,
            'dispatch_identity_confirmed': False,
            'independent_ovh_verified': False, 'browser_accepted': False}
