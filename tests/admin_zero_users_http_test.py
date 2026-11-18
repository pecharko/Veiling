#!/usr/bin/env python3
"""Isolated HTTP smoke test for admin login when no admin account exists.

Uses a fake database and local PHP server. Does not use production, credentials,
or any external network connection. This is NOT a live MySQL integration test.
"""
import contextlib
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
LOGIN = ROOT / 'catalogus-public/admin/login.php'

FAKE_BOOTSTRAP = r'''<?php
function require_no_index() {}
function admin_session() {}
function db() { return new class {
    public function query($sql) { return new class {
        public function fetchColumn() { return (int)(getenv('FAKE_ADMIN_COUNT') ?: 0); }
    }; }
}; }
function redirect($path) { header('Location: /' . $path, true, 303); exit; }
function is_post() { return $_SERVER['REQUEST_METHOD'] === 'POST'; }
function take_flash() { return null; }
function csrf_field() { return '<input type="hidden" name="csrf" value="fake">'; }
function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES); }
function url($path) { return '/' . $path; }
'''


class AdminWithoutUsersHTTP(unittest.TestCase):
    def http_case(self, admin_count, method, expected_status):
        with tempfile.TemporaryDirectory(prefix='auction-login-http-') as tmp:
            base = Path(tmp)
            (base / 'catalogus-public/admin').mkdir(parents=True)
            (base / 'private').mkdir()
            shutil.copyfile(LOGIN, base / 'catalogus-public/admin/login.php')
            (base / 'private/bootstrap.php').write_text(FAKE_BOOTSTRAP)
            (base / 'private/catalog_mutex.php').write_text('<?php\n')
            (base / 'private/analytics_control.php').write_text('<?php\n')
            with socket.socket() as listener:
                listener.bind(('127.0.0.1', 0))
                port = listener.getsockname()[1]
            env = dict(os.environ, FAKE_ADMIN_COUNT=str(admin_count))
            with subprocess.Popen(
                ['php', '-S', '127.0.0.1:' + str(port), '-t', str(base / 'catalogus-public')],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=env,
            ) as server:
                url = 'http://127.0.0.1:%d/admin/login.php' % port
                response = None
                for _ in range(40):
                    try:
                        request = urllib.request.Request(
                            url,
                            data=(b'csrf=fake' if method == 'POST' else None),
                            method=method,
                        )
                        response = urllib.request.urlopen(request, timeout=1)
                    except urllib.error.HTTPError as error:
                        response = error
                    except urllib.error.URLError:
                        time.sleep(0.05)
                        continue
                    break
                self.assertIsNotNone(response, 'Local PHP server did not respond')
                with contextlib.closing(response):
                    body = response.read().decode('utf-8', 'replace')
                    self.assertEqual(response.status, expected_status)
                    if admin_count == 0:
                        self.assertIn('no-store', response.headers.get('Cache-Control', ''))
                        self.assertIn('Beheer tijdelijk niet beschikbaar', body)
                        self.assertNotIn('admin/setup.php', body)
                    else:
                        self.assertIn('name="email"', body)
                        self.assertIn('name="password"', body)
                server.terminate()
                server.wait(timeout=3)

    def test_no_admin_get_is_closed(self):
        self.http_case(0, 'GET', 503)

    def test_no_admin_post_is_closed(self):
        self.http_case(0, 'POST', 503)

    def test_existing_admin_login_form_is_available(self):
        self.http_case(1, 'GET', 200)


if __name__ == '__main__':
    unittest.main()
