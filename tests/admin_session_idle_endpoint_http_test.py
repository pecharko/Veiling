#!/usr/bin/env python3
"""Isolated HTTP checks for actual renewal PHP endpoint using fake auth bootstrap.

Does not prove real DB session or roles; no OVH connectivity.
"""
import json
import pathlib
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
ENDPOINT = ROOT / "catalogus-public/admin/sessie-verlengen.php"
BOOTSTRAP = """<?php
function require_no_index(): void { header('X-Robots-Tag: noindex'); }
function admin_session(): void {}
function is_post(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }
function check_csrf(): void {
    if (($_POST['csrf'] ?? '') !== 'fixture-csrf') {
        http_response_code(403); exit('CSRF refused');
    }
}
function require_auth(): void {
    if (($_SERVER['HTTP_X_TEST_AUTH'] ?? '') !== 'valid') {
        header('Location: /admin/login.php', true, 303); exit;
    }
}
function admin_idle_expires_at(): int { return 1800003600; }
"""

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None

class RenewalHTTPTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix="issue23-http-")
        root = pathlib.Path(cls.temp.name)
        (root / "private").mkdir()
        (root / "catalogus-public/admin").mkdir(parents=True)
        (root / "private/bootstrap.php").write_text(BOOTSTRAP)
        (root / "catalogus-public/admin/sessie-verlengen.php").write_bytes(ENDPOINT.read_bytes())
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        cls.url = f"http://127.0.0.1:{port}/admin/sessie-verlengen.php"
        cls.process = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{port}", "-t", str(root / "catalogus-public")],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, cwd=str(root),
        )
        for _ in range(60):
            try:
                urllib.request.urlopen(cls.url, timeout=0.2)
            except urllib.error.HTTPError:
                break
            except urllib.error.URLError:
                time.sleep(0.05)

    @classmethod
    def tearDownClass(cls):
        cls.process.terminate()
        cls.process.wait(timeout=5)
        cls.temp.cleanup()

    def request(self, method, csrf=None, auth=None):
        data = None if method == "GET" else f"csrf={csrf or ''}".encode()
        headers = {"Accept": "application/json"}
        if auth is not None:
            headers["X-Test-Auth"] = auth
        if method == "POST":
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        req = urllib.request.Request(self.url, data=data, headers=headers, method=method)
        try:
            with urllib.request.build_opener(NoRedirect).open(req, timeout=3) as r:
                return r.status, r.headers, r.read().decode()
        except urllib.error.HTTPError as exc:
            return exc.code, exc.headers, exc.read().decode()

    def test_get_disallowed(self):
        code, headers, body = self.request("GET")
        self.assertEqual(code, 405)
        self.assertEqual(headers.get("Allow"), "POST")
        self.assertNotIn("expires_at", body)

    def test_invalid_csrf(self):
        code, _, body = self.request("POST", csrf="wrong", auth="valid")
        self.assertEqual(code, 403)
        self.assertNotIn("expires_at", body)

    def test_valid_csrf_without_login(self):
        code, headers, body = self.request("POST", csrf="fixture-csrf")
        self.assertEqual(code, 303)
        self.assertEqual(headers.get("Location"), "/admin/login.php")
        self.assertNotIn("expires_at", body)

    def test_authorized_json_no_store(self):
        code, headers, body = self.request("POST", csrf="fixture-csrf", auth="valid")
        self.assertEqual(code, 200)
        self.assertIn("application/json", headers.get("Content-Type"))
        self.assertEqual(headers.get("Cache-Control"), "no-store")
        self.assertEqual(json.loads(body), {"ok": True, "expires_at": 1800003600})

if __name__ == "__main__":
    unittest.main(verbosity=2)
