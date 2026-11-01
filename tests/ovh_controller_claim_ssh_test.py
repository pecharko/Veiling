"""No OVH sessions: fake SSH runner, real local pipe bounds and PHP CLI in CI."""
import base64
from dataclasses import replace
import json
import os
from pathlib import Path
import shutil
import stat
import struct
import subprocess
import sys
import time
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
sys.path.insert(0, str(ROOT / 'tests'))
import ovh_controller_claim_ssh as ssh
import ovh_controller_claim_adapter as adapter
from owner_bound_dispatch_ledger import canonical
from ovh_controller_claim_adapter_test import KEY, TIME, binding_fixture, TestAudit, SignedTransport


def config():
    algorithm = b'ssh-ed25519'
    # Public, synthetic wire key; no private key exists or is read by these tests.
    key = struct.pack('>I', len(algorithm)) + algorithm + struct.pack('>I', 32) + bytes(32)
    return ssh.SSHConfig(host='hosting.example.invalid', user='fixture', port=22,
        identity_file='/tmp/fixture-ssh-key-not-installed',
        known_host='hosting.example.invalid ssh-ed25519 ' + base64.b64encode(key).decode(),
        private_root='/home/fixture/private/veiling-controller', revision='a' * 40,
        executor_sha256='b' * 64, store_sha256='c' * 64, php_binary='/usr/local/php8.2/bin/php')


class FakeSSH:
    def __init__(self, cfg=None):
        self.config = cfg or config()
        self.calls = []
        self.protocol = SignedTransport()
        self.known_file = None

    def __call__(self, argv, payload, limit, timeout):
        self.calls.append((argv, payload, limit, timeout))
        known = next(x.split('=', 1)[1] for x in argv if x.startswith('UserKnownHostsFile='))
        self.known_file = known
        if Path(known).read_text() != self.config.known_host + '\n':
            raise AssertionError('Wrong pinned known-hosts file')
        if stat.S_IMODE(Path(known).stat().st_mode) != 0o600:
            raise AssertionError('Wrong known-hosts permissions')
        if len(self.calls) == 1:
            paths = self.config.paths()
            return ''.join(h + '  ' + p + '\n' for h, p in zip(
                (self.config.executor_sha256, self.config.store_sha256), paths)).encode(), b'', 0
        frame = json.loads(payload)
        body, signature = self.protocol.exchange(frame['body'].encode(), frame['signature'])
        return canonical({'body': body.decode(), 'signature': signature}).encode() + b'\n', b'', 0


class SSHTests(unittest.TestCase):
    def test_real_openssh_accepts_generated_options_in_local_config_only_mode(self):
        if not Path('/usr/bin/ssh').is_file():
            self.skipTest('OpenSSH client missing; no remote connection attempted')
        transport = ssh.SSHTransport(config(), runner=Mock())
        argv = transport._argv('/tmp/synthetic-known-hosts-not-installed', 'fixed-command')
        result = subprocess.run([argv[0], '-G', *argv[1:]], capture_output=True, timeout=5)
        self.assertEqual(result.returncode, 0, result.stderr.decode())
        output = result.stdout.decode().lower()
        self.assertIn('hostname hosting.example.invalid', output)
        self.assertIn('stricthostkeychecking true', output)
        self.assertIn('identityagent none', output)

    def test_public_execute_never_connects_or_reads_credentials_even_with_flags(self):
        with patch.object(ssh.subprocess, 'Popen', side_effect=AssertionError('SSH invoked')), \
                patch('builtins.open', side_effect=AssertionError('Credential read')):
            result = ssh.execute(enabled=True, config=config(), transport_ready=True)
        self.assertEqual(result['status'], 'DISABLED')
        self.assertFalse(result['dispatch_started'])

    def test_code_pin_precedes_only_executor_payload_and_protocol_hmac_still_required(self):
        runner = FakeSSH()
        transport = ssh.SSHTransport(config(), runner=runner)
        ledger = adapter.RemoteLedger(transport, KEY, clock=lambda: TIME)
        self.assertTrue(ledger.reserve(binding_fixture()))
        self.assertEqual(len(runner.calls), 2)
        first, second = runner.calls
        self.assertEqual(first[1], b'')
        self.assertTrue(first[0][-1].startswith('sha256sum -- '))
        self.assertIn(config().revision, first[0][-1])
        self.assertEqual(second[0][-1], config().php_binary + ' -f ' + config().paths()[0])
        self.assertNotIn(second[1].decode(), ' '.join(second[0]))
        self.assertFalse(Path(runner.known_file).exists())
        for argv, _, _, _ in runner.calls:
            for option in ('StrictHostKeyChecking=yes', 'BatchMode=yes', 'ConnectionAttempts=1',
                           'IdentityAgent=none', 'IdentitiesOnly=yes', 'PasswordAuthentication=no',
                           'KbdInteractiveAuthentication=no', 'ForwardAgent=no', 'ClearAllForwardings=yes',
                           'PermitLocalCommand=no', 'ControlPath=none', 'ProxyCommand=none',
                           'GlobalKnownHostsFile=/dev/null', 'UpdateHostKeys=no'):
                self.assertIn(option, argv)
            self.assertEqual(argv[-2], 'fixture@hosting.example.invalid')
            self.assertEqual(argv[:3], ['/usr/bin/ssh', '-F', '/dev/null'])

    def test_invalid_destination_pin_revision_paths_and_binary_precede_all_io(self):
        bad = [{'host': '-oProxyCommand=bad'}, {'user': 'a;bad'}, {'port': True},
               {'port': 0}, {'known_host': config().known_host.replace('hosting.example.invalid', '*')},
               {'known_host': config().known_host + '\nextra'}, {'known_host': 'host ssh-ed25519 ???'},
               {'revision': '../main'}, {'executor_sha256': 'bad'}, {'store_sha256': None},
               {'private_root': '/home/fixture/www/controller'}, {'private_root': '/tmp/../private'},
               {'private_root': '/tmp/private;bad'}, {'identity_file': '/tmp/key\n-oProxyCommand=bad'},
               {'php_binary': '/bin/sh'}, {'php_binary': '/usr/bin/php;bad'}]
        runner = Mock()
        for update in bad:
            with self.subTest(update=update), self.assertRaises(ValueError):
                ssh.SSHTransport(replace(config(), **update), runner=runner)
        self.assertFalse(runner.mock_calls)

    def test_nondefault_port_requires_exact_port_bound_pin(self):
        changed = replace(config(), port=2222)
        with self.assertRaises(ValueError):
            ssh.SSHTransport(changed, runner=Mock())
        changed = replace(changed, known_host=config().known_host.replace(
            'hosting.example.invalid', '[hosting.example.invalid]:2222'))
        changed.validate()

    def test_hash_drift_missing_file_untrusted_host_or_stderr_never_gets_payload(self):
        outcomes = [(b'wrong hash\n', b'', 0), (b'', b'private remote detail', 255),
                    (b'', b'', 1), (b'', b'hostkey mismatch', 255)]
        for outcome in outcomes:
            runner = Mock(return_value=outcome)
            with self.subTest(code=outcome[2]), self.assertRaisesRegex(RuntimeError, 'NO RETRY') as failure:
                ssh.SSHTransport(config(), runner=runner).exchange(b'{}', 'd' * 64)
            self.assertEqual(runner.call_count, 1)
            self.assertEqual(runner.call_args.args[1], b'')
            self.assertNotIn('private remote detail', str(failure.exception))

    def test_executor_ambiguity_disabled_or_bad_frames_never_reinvoked(self):
        variants = [(b'{"status":"DISABLED"}\n', b'', 78), (b'', b'private exception', 1),
                    (b'{"body":"x","signature":"bad"}\n', b'', 0),
                    (b'{"body":"x"}\n', b'', 0), (b'not-json\n', b'', 0),
                    (b'{}', b'', 0), (b'{}\n', b'warning', 0)]
        for outcome in variants:
            fake = FakeSSH()
            calls = []
            def runner(*args):
                calls.append(args)
                if len(calls) == 1:
                    return fake(*args)
                return outcome
            with self.subTest(outcome=outcome[2]), self.assertRaisesRegex(RuntimeError, 'NO RETRY'):
                ssh.SSHTransport(config(), runner=runner).exchange(b'{}', 'd' * 64)
            self.assertEqual(len(calls), 2)

    def test_ssh_success_never_replaces_response_mac_verification(self):
        fake = FakeSSH()
        calls = []
        def runner(*args):
            calls.append(args)
            result = fake(*args)
            if len(calls) == 2:
                frame = json.loads(result[0])
                frame['signature'] = '0' * 64
                return canonical(frame).encode() + b'\n', b'', 0
            return result
        ledger = adapter.RemoteLedger(ssh.SSHTransport(config(), runner=runner), KEY, clock=lambda: TIME)
        with self.assertRaisesRegex(RuntimeError, 'NO RETRY'):
            ledger.reserve(binding_fixture())
        self.assertEqual(len(calls), 2)

    def test_timeout_after_payload_keeps_independent_authorization_consumed(self):
        fake, audit = FakeSSH(), TestAudit()
        calls = []
        def runner(*args):
            calls.append(args)
            if len(calls) == 1:
                return fake(*args)
            raise TimeoutError('synthetic-private-error')
        remote = adapter.RemoteLedger(ssh.SSHTransport(config(), runner=runner), KEY, clock=lambda: TIME)
        ledger = adapter.RestoreSafeLedger(remote, audit)
        binding = binding_fixture()
        with self.assertRaisesRegex(RuntimeError, 'NO RETRY') as failure:
            ledger.reserve(binding)
        self.assertNotIn('synthetic-private-error', str(failure.exception))
        self.assertFalse(ledger.reserve(binding))
        self.assertEqual(len(calls), 2)
        self.assertEqual(audit.lookup(binding['nonce']), binding)

    def test_invalid_request_does_not_even_run_hash_preflight(self):
        runner = Mock()
        for body, sig in ((b'', 'd' * 64), (b'x' * (ssh.MAX_WIRE + 1), 'd' * 64), (b'{}', 'bad')):
            with self.assertRaises(ValueError):
                ssh.SSHTransport(config(), runner=runner).exchange(body, sig)
        self.assertFalse(runner.mock_calls)


class PipeBoundsTests(unittest.TestCase):
    def test_real_subprocess_full_duplex_drain_has_no_pipe_deadlock(self):
        payload = b'x' * 200000
        command = (sys.executable, '-c',
                   'import sys; sys.stderr.write("ok"); sys.stdout.buffer.write(sys.stdin.buffer.read())')
        out, err, code = ssh._bounded_run(command, payload, len(payload), 5)
        self.assertEqual((out, err, code), (payload, b'ok', 0))

    def test_real_timeout_stdout_and_stderr_limits_stop_process(self):
        cases = [('import time;time.sleep(10)', 100, 0.1),
                 ('import sys;sys.stdout.buffer.write(b"x"*100000)', 100, 3),
                 ('import sys;sys.stderr.buffer.write(b"x"*100000)', 100, 3)]
        for code, limit, timeout in cases:
            start = time.monotonic()
            with self.subTest(code=code), self.assertRaisesRegex(RuntimeError, 'NO RETRY'):
                ssh._bounded_run((sys.executable, '-c', code), b'', limit, timeout)
            self.assertLess(time.monotonic()-start, 4)


class PHPCLIIntegrationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not shutil.which('php'):
            if os.environ.get('OVH_CLAIM_REQUIRE_PHP') == '1':
                raise AssertionError('Mandatory PHP CLI tests cannot be skipped in CI')
            raise unittest.SkipTest('PHP absent locally; mandatory real PHP tests run in CI')

    def test_private_stdio_harness_completes_real_python_php_exchange_without_ssh(self):
        fake = FakeSSH()
        calls = []
        def runner(argv, payload, limit, timeout):
            calls.append((argv, payload))
            if len(calls) == 1:
                return fake(argv, payload, limit, timeout)
            env = {**os.environ, 'OVH_CLAIM_TEST_NOW': str(TIME)}
            p = subprocess.run([shutil.which('php'), str(ROOT/'tests/ovh_controller_claim_stdio_test.php')],
                               input=payload, capture_output=True, timeout=10, env=env)
            return p.stdout, p.stderr, p.returncode
        ledger = adapter.RemoteLedger(ssh.SSHTransport(config(), runner=runner), KEY, clock=lambda: TIME)
        self.assertTrue(ledger.reserve(binding_fixture()))
        self.assertEqual(len(calls), 2)

    def test_real_php_rejects_malformed_oversized_duplicate_or_unsigned_stdio_without_details(self):
        cases = [b'{}\n', b'not-json\n', b'{"body":"{}","signature":"bad"}\n',
                 b'{"body":"{}","body":"{}","signature":"bad"}\n',
                 b'x' * (ssh.MAX_FRAME+1) + b'\n']
        for payload in cases:
            result = subprocess.run([shutil.which('php'), str(ROOT/'tests/ovh_controller_claim_stdio_test.php')],
                                    input=payload, capture_output=True, timeout=10,
                                    env={**os.environ, 'OVH_CLAIM_TEST_NOW': str(TIME)})
            self.assertEqual(result.returncode, 65)
            self.assertEqual(json.loads(result.stdout)['status'], 'REJECTED')
            self.assertEqual(result.stderr, b'')
            self.assertNotIn(KEY, result.stdout)

    def test_real_php_read_timeout_rejects_open_unfinished_stream(self):
        p = subprocess.Popen([shutil.which('php'), str(ROOT/'tests/ovh_controller_claim_stdio_test.php'),
                              '--test-short-timeout'], stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                              stderr=subprocess.PIPE, env={**os.environ, 'OVH_CLAIM_TEST_NOW': str(TIME)})
        try:
            p.wait(timeout=3)  # Keep stdin open: the executor must enforce its own read timeout.
            self.assertEqual(p.returncode, 65)
            self.assertEqual(json.loads(p.stdout.read())['status'], 'REJECTED')
            self.assertEqual(p.stderr.read(), b'')
        finally:
            if p.poll() is None:
                p.kill(); p.wait()
            for stream in (p.stdin,p.stdout,p.stderr):
                stream.close()

    def test_real_executor_public_cli_stays_disabled_without_reading_stdin(self):
        p = subprocess.Popen([shutil.which('php'), str(ROOT/'scripts/ovh_controller_claim_executor.php')],
                             stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                             env={**os.environ, 'OVH_CLAIM_ENABLED': 'true'})
        try:
            p.wait(timeout=3)
            self.assertEqual(p.returncode, 78)
            self.assertEqual(json.loads(p.stdout.read())['status'], 'DISABLED')
            self.assertEqual(p.stderr.read(), b'')
        finally:
            if p.poll() is None:
                p.kill(); p.wait()
            for stream in (p.stdin,p.stdout,p.stderr):
                stream.close()


if __name__ == '__main__':
    unittest.main()
