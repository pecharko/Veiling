"""Dormant WSGI packaging and authenticated read-only webhook review.

The exported application and CLI are unconditionally disabled. ReviewApplication
is a host-independent review seam: GET evidence only, no ledger or sender. This
file opens no socket, loads no credential and installs no service on import.
"""
from datetime import datetime, timezone
import hashlib
import hmac
import json
import re

from owner_bound_dispatch import GitHubEvidence, REPO
from owner_bound_dispatch_ledger import binding_digest
from shared_ovh_controller_adapter import _json_bytes, parse_approval

REPOSITORY_ID = 1423110590
PATH = '/hooks/github/owner-bound-review'
MAX_BODY = 128 * 1024
SIGNATURE = re.compile(r'sha256=[0-9a-f]{64}')
DELIVERY = re.compile(r'[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}')
FLAGS = {'dispatch_started': False, 'write_allowed': False,
         'retry_allowed': False, 'production_accepted': False}


class AuthenticationError(ValueError):
    pass


def _response(start_response, code, status, **data):
    body = json.dumps({**data, **FLAGS, 'status': status},
                      sort_keys=True, separators=(',', ':')).encode()
    start_response(code, [('Content-Type', 'application/json'),
                          ('Content-Length', str(len(body))),
                          ('Cache-Control', 'no-store'),
                          ('X-Content-Type-Options', 'nosniff')])
    return [body]


def application(_environ, start_response):
    """Production export: no body/credential reads, flags or network allowed."""
    return _response(start_response, '503 Service Unavailable', 'DISABLED')


def _positive(value):
    if type(value) is not int or value <= 0:
        raise ValueError('Invalid identity')
    return value


def _user(value):
    if (type(value) is not dict or value.get('login') != 'pecharko'
            or value.get('type') != 'User'):
        raise ValueError('Not an owner user')
    return _positive(value.get('id'))


def authenticate(environ, secret):
    """Verify raw-body HMAC, then a bounded GitHub issue_comment envelope.

    Delivery ID and other HTTP headers are not signed by GitHub. Never use the
    delivery ID as consent/nonce/replay authority. Fresh independent owner
    evidence and the separate durable dispatch ledger remain necessary.
    """
    if type(secret) is not bytes or not 32 <= len(secret) <= 1024:
        raise AuthenticationError('Invalid signing credential')
    if (environ.get('REQUEST_METHOD') != 'POST'
            or environ.get('PATH_INFO') != PATH
            or environ.get('SCRIPT_NAME', '') != ''
            or environ.get('QUERY_STRING', '') != ''
            or environ.get('wsgi.url_scheme') != 'https'
            or environ.get('CONTENT_TYPE') not in
            ('application/json', 'application/json; charset=utf-8')
            or environ.get('HTTP_TRANSFER_ENCODING')
            or environ.get('HTTP_CONTENT_ENCODING')):
        raise ValueError('Invalid request framing')
    length = environ.get('CONTENT_LENGTH')
    if type(length) is not str or not re.fullmatch(r'[1-9][0-9]{0,5}', length):
        raise ValueError('Missing bounded content length')
    length = int(length)
    if length > MAX_BODY:
        raise ValueError('Body too large')
    signature = environ.get('HTTP_X_HUB_SIGNATURE_256')
    delivery = environ.get('HTTP_X_GITHUB_DELIVERY')
    if (type(signature) is not str or not SIGNATURE.fullmatch(signature)
            or type(delivery) is not str or not DELIVERY.fullmatch(delivery)
            or environ.get('HTTP_X_GITHUB_EVENT') != 'issue_comment'):
        raise AuthenticationError('Missing or ambiguous GitHub headers')
    raw = environ['wsgi.input'].read(length)
    if type(raw) is not bytes or len(raw) != length:
        raise ValueError('Truncated request')
    expected = 'sha256=' + hmac.new(secret, raw, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, signature):
        raise AuthenticationError('Invalid webhook signature')
    event = _json_bytes(raw)
    if type(event) is not dict or event.get('action') != 'created':
        raise ValueError('Not a created owner comment')
    repo, issue, comment = (event.get(k) for k in ('repository', 'issue', 'comment'))
    if (type(repo) is not dict or repo.get('full_name') != REPO
            or type(repo.get('id')) is not int or repo['id'] != REPOSITORY_ID
            or type(issue) is not dict or issue.get('pull_request') is not None
            or issue.get('state') != 'open' or type(comment) is not dict):
        raise ValueError('Wrong repository, issue or comment')
    owner_id = _user(repo.get('owner'))
    if any(_user(user) != owner_id for user in
           (event.get('sender'), issue.get('user'), comment.get('user'))):
        raise ValueError('Webhook owner identity mismatch')
    issue_id, comment_id = _positive(issue.get('number')), _positive(comment.get('id'))
    if comment.get('issue_url') != (
            'https://api.github.com/repos/' + REPO + '/issues/' + str(issue_id)):
        raise ValueError('Comment/issue mismatch')
    body = comment.get('body')
    approval = parse_approval(body)
    if 'nonce' not in approval:
        raise ValueError('Missing exact owner-bound nonce')
    return {'issue_id': issue_id, 'comment_id': comment_id, 'owner_id': owner_id,
            'approval_sha256': hashlib.sha256(body.encode()).hexdigest()}


class BoundReader:
    """Rebind EVERY independent prepare pass to the authenticated raw event.

    This object does not grant authority. An eventual separately reviewed
    dispatcher must retain the nonce/reservation, recheck and no-retry gates.
    """
    def __init__(self, reader, authenticated):
        self.reader = reader
        self.authenticated = dict(authenticated)

    def prepare(self, issue_id, comment_id, now):
        expected = self.authenticated
        if issue_id != expected['issue_id'] or comment_id != expected['comment_id']:
            raise ValueError('Different event identity')
        binding = self.reader.prepare(issue_id, comment_id, now)
        if (binding.get('repository') != REPO
                or any(binding.get(k) != expected[k] for k in expected)):
            raise ValueError('Independent evidence differs from signed event')
        return binding


class ReviewApplication:
    """WSGI read-only review seam, independently of a future host/server choice.

    Credentials/dependencies are explicitly supplied by a trusted host or tests;
    they can never arrive through HTTP or environment activation flags. No
    dispatcher, ledger provisioning, service install or network listener here.
    """
    def __init__(self, secret, reader, *, clock=None):
        if type(secret) is not bytes or not 32 <= len(secret) <= 1024:
            raise ValueError('Require a strong webhook signing credential')
        if not isinstance(reader, GitHubEvidence):
            raise ValueError('Require the independent read-only GitHub reader')
        self.secret = secret
        self.reader = reader
        self.clock = clock or (lambda: datetime.now(timezone.utc))

    def __call__(self, environ, start_response):
        try:
            event = authenticate(environ, self.secret)
        except AuthenticationError:
            return _response(start_response, '401 Unauthorized', 'REJECTED')
        except Exception:
            return _response(start_response, '400 Bad Request', 'REJECTED')
        try:
            binding = BoundReader(self.reader, event).prepare(
                event['issue_id'], event['comment_id'], self.clock())
            digest = binding_digest(binding)
        except Exception:
            # Never return API responses, approval text, credentials, signature
            # or exception details to HTTP clients or automatic access logs.
            return _response(start_response, '503 Service Unavailable',
                             'EVIDENCE_UNAVAILABLE_STOP_NO_RETRY')
        return _response(start_response, '200 OK', 'VERIFIED_READ_ONLY',
                         binding_sha256=digest)


def main():
    # Deliberately no argparse, secret loading, socket, supervisor or live path.
    print(json.dumps({**FLAGS, 'status': 'DISABLED'}))
    return 78


if __name__ == '__main__':
    raise SystemExit(main())
