"""Pinned private SSH transport proposal; no connection or credential defaults.

Only explicitly constructed SSHTransport.exchange() can invoke SSH. The public
execute() stays DISABLED. No upload, install, dispatch, migration or retry here.
"""
import base64
from dataclasses import dataclass
import os
from pathlib import PurePosixPath
import re
import selectors
import shlex
import struct
import subprocess
import tempfile
import time

from owner_bound_dispatch_ledger import canonical
from shared_ovh_controller_adapter import HASH, SHA, _json_bytes
from ovh_controller_claim_adapter import MAX_WIRE

FILES = ('ovh_controller_claim_executor.php', 'ovh_controller_claim_store.php')
MAX_FRAME = 2 * MAX_WIRE
PHP_BINARIES = ('/usr/bin/php8.2', '/usr/bin/php8.3', '/usr/bin/php8.4', '/usr/bin/php8.5',
                '/usr/local/php8.2/bin/php', '/usr/local/php8.3/bin/php',
                '/usr/local/php8.4/bin/php', '/usr/local/php8.5/bin/php')


def _path(value):
    if (type(value) is not str or not value.startswith('/')
            or any(not re.fullmatch(r'[A-Za-z0-9_.-]+', s) or s in ('.', '..')
                   for s in value.split('/')[1:])):
        raise ValueError('Unsafe absolute runtime path')
    return value


@dataclass(frozen=True)
class SSHConfig:
    host: str
    user: str
    port: int
    identity_file: str
    known_host: str
    private_root: str
    revision: str
    executor_sha256: str
    store_sha256: str
    php_binary: str

    def validate(self):
        if (type(self.host) is not str or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9.-]{0,252}', self.host)
                or type(self.user) is not str or not re.fullmatch(r'[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}', self.user)
                or type(self.port) is not int or not 1 <= self.port <= 65535):
            raise ValueError('Invalid SSH destination')
        _path(self.identity_file)
        _path(self.private_root)
        if set(PurePosixPath(self.private_root).parts) & {'www', 'public_html', 'catalogus-public'}:
            raise ValueError('Controller bundle must be outside known web roots')
        if type(self.revision) is not str or not SHA.fullmatch(self.revision):
            raise ValueError('Require immutable reviewed code revision')
        if any(type(h) is not str or not HASH.fullmatch(h)
               for h in (self.executor_sha256, self.store_sha256)):
            raise ValueError('Missing exact executor/store code digests')
        if self.php_binary not in PHP_BINARIES:
            raise ValueError('Unreviewed PHP binary')
        pin = self.known_host
        if type(pin) is not str or '\n' in pin or '\r' in pin or len(pin) > 16384:
            raise ValueError('Require one exact pinned hostkey line')
        fields = pin.split()
        host = self.host if self.port == 22 else '[' + self.host + ']:' + str(self.port)
        if (len(fields) != 3 or fields[0] != host
                or fields[1] not in ('ssh-ed25519', 'ecdsa-sha2-nistp256', 'ssh-rsa')):
            raise ValueError('Wildcard, alias or unknown SSH hostkey')
        try:
            raw = base64.b64decode(fields[2], validate=True)
            n = struct.unpack('>I', raw[:4])[0]
            if raw[4:4+n].decode('ascii') != fields[1] or len(raw) <= 4+n:
                raise ValueError('Malformed SSH hostkey')
        except Exception:
            raise ValueError('Malformed pinned hostkey') from None

    def paths(self):
        return tuple(self.private_root + '/' + self.revision + '/' + f for f in FILES)


def _bounded_run(argv, payload, limit, timeout):
    """Drain all pipes with hard byte/time bounds; never expose stderr/content.

    A timeout/kill cannot prove that remote PHP did not commit. It must never
    cause a second invocation; the independent reservation remains consumed.
    """
    process = None
    try:
        process = subprocess.Popen(argv, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                   stderr=subprocess.PIPE, start_new_session=True)
        end = time.monotonic() + timeout
        out, err, offset = bytearray(), bytearray(), 0
        with selectors.DefaultSelector() as selector:
            for stream, kind in ((process.stdout, 'out'), (process.stderr, 'err')):
                os.set_blocking(stream.fileno(), False)
                selector.register(stream, selectors.EVENT_READ, kind)
            if payload:
                os.set_blocking(process.stdin.fileno(), False)
                selector.register(process.stdin, selectors.EVENT_WRITE, 'in')
            else:
                process.stdin.close()
            while selector.get_map():
                remaining = end - time.monotonic()
                if remaining <= 0:
                    raise TimeoutError()
                for key, _ in selector.select(min(remaining, 0.2)):
                    if key.data == 'in':
                        written = os.write(key.fd, payload[offset:offset+16384])
                        offset += written
                        if offset == len(payload):
                            selector.unregister(key.fileobj)
                            key.fileobj.close()
                    else:
                        target, bound = (out, limit) if key.data == 'out' else (err, 4096)
                        chunk = os.read(key.fd, min(65536, bound-len(target)+1))
                        if not chunk:
                            selector.unregister(key.fileobj)
                            key.fileobj.close()
                        else:
                            target.extend(chunk)
                            if len(target) > bound:
                                raise ValueError('Process output limit')
            code = process.wait(timeout=max(0.001, end-time.monotonic()))
            if offset != len(payload):
                raise ValueError('Unsent input')
        return bytes(out), bytes(err), code
    except Exception:
        raise RuntimeError('SSH outcome unknown; STOP, NO RETRY') from None
    finally:
        if process is not None:
            if process.poll() is None:
                process.kill()
            try:
                process.wait(timeout=2)
            finally:
                for stream in (process.stdin, process.stdout, process.stderr):
                    if stream is not None and not stream.closed:
                        stream.close()


class SSHTransport:
    def __init__(self, config, *, runner=None):
        if type(config) is not SSHConfig:
            raise ValueError('Require explicit reviewed SSH configuration')
        config.validate()
        self.config = config
        self.runner = runner or _bounded_run

    def _argv(self, known_hosts, command):
        c = self.config
        options = ('BatchMode=yes', 'StrictHostKeyChecking=yes',
                   'UserKnownHostsFile=' + known_hosts, 'GlobalKnownHostsFile=/dev/null',
                   'VerifyHostKeyDNS=no', 'UpdateHostKeys=no', 'CheckHostIP=no', 'CanonicalizeHostname=no',
                   'HostKeyAlgorithms=ssh-ed25519,ecdsa-sha2-nistp256,rsa-sha2-512,rsa-sha2-256',
                   'IdentitiesOnly=yes', 'IdentityAgent=none', 'AddKeysToAgent=no',
                   'CertificateFile=none', 'PreferredAuthentications=publickey',
                   'PasswordAuthentication=no', 'KbdInteractiveAuthentication=no',
                   'ForwardAgent=no', 'ClearAllForwardings=yes', 'PermitLocalCommand=no',
                   'RequestTTY=no', 'ControlMaster=no', 'ControlPath=none',
                   'ProxyCommand=none', 'ConnectTimeout=10', 'ConnectionAttempts=1')
        args = ['/usr/bin/ssh', '-F', '/dev/null', '-T', '-p', str(c.port), '-i', c.identity_file]
        for option in options:
            args.extend(('-o', option))
        return [*args, '--', c.user + '@' + c.host, command]

    def exchange(self, body, signature):
        if (type(body) is not bytes or not 0 < len(body) <= MAX_WIRE
                or type(signature) is not str or not HASH.fullmatch(signature)):
            raise ValueError('Invalid bounded signed request')
        self.config.validate()
        try:
            frame = canonical({'body': body.decode('utf-8'), 'signature': signature}).encode() + b'\n'
            if len(frame) > MAX_FRAME:
                raise ValueError('Oversized SSH frame')
            paths = self.config.paths()
            # No payload is sent before this fixed read-only hash preflight.
            with tempfile.TemporaryDirectory(prefix='ovh-claim-hostkey-') as tmp:
                known = tmp + '/known_hosts'
                with open(known, 'x', encoding='ascii') as pin:
                    os.chmod(known, 0o600)
                    pin.write(self.config.known_host + '\n')
                stdout, stderr, code = self.runner(
                    self._argv(known, shlex.join(('sha256sum', '--', *paths))), b'', 4096, 20)
                expected = ''.join(h + '  ' + p + '\n' for h, p in zip(
                    (self.config.executor_sha256, self.config.store_sha256), paths)).encode()
                if code != 0 or stderr or stdout != expected:
                    raise ValueError('Remote code identity unverified')
                # Exactly one fixed executor call. Never run SFTP/upload/install,
                # a shell command supplied by a request, or a retry on failure.
                command = shlex.join((self.config.php_binary, '-f', paths[0]))
                stdout, stderr, code = self.runner(self._argv(known, command), frame, MAX_FRAME, 30)
                if code != 0 or stderr or not stdout.endswith(b'\n'):
                    raise ValueError('Executor outcome unknown')
                response = _json_bytes(stdout[:-1])
                if (type(response) is not dict or set(response) != {'body', 'signature'}
                        or canonical(response).encode() != stdout[:-1]
                        or type(response['body']) is not str
                        or not 0 < len(response['body'].encode()) <= MAX_WIRE
                        or type(response['signature']) is not str or not HASH.fullmatch(response['signature'])):
                    raise ValueError('Invalid executor response frame')
                # RemoteLedger still verifies response HMAC, nonce, operation
                # and binding; a pinned SSH channel is not their replacement.
                return response['body'].encode(), response['signature']
        except Exception:
            raise RuntimeError('SSH claim outcome unknown; STOP, NO RETRY') from None


def execute(*_args, **_kwargs):
    return {'status': 'DISABLED', 'dispatch_started': False,
            'write_allowed': False, 'retry_allowed': False}
