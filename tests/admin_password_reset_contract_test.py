"""Offline contract tests for password reset; no DB or mail sent."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]

class PasswordResetContractTest(unittest.TestCase):
    def test_login_link(self):
        self.assertIn("admin/wachtwoord-vergeten.php", (ROOT / "catalogus-public/admin/login.php").read_text())
    def test_request_is_non_enumerating_and_csrf_protected(self):
        src = (ROOT / "catalogus-public/admin/wachtwoord-vergeten.php").read_text()
        self.assertIn("check_csrf()", src)
        self.assertIn("Als dit e-mailadres aan een actief account gekoppeld is", src)
        self.assertIn("require_no_index()", src)
    def test_tokens_limited_and_hashed(self):
        src = (ROOT / "private/admin_password_reset.php").read_text()
        for value in ["random_bytes(32)", "hash('sha256', $token)", "INTERVAL 30 MINUTE",
                      "ipCount >= 5", "emailCount >= 3", "used_at IS NULL",
                      "is_active=1", "noreply@41lokeren.be", "GET_LOCK"]:
            self.assertIn(value, src)
    def test_reset_changes_hash_and_requires_csrf(self):
        src = (ROOT / "catalogus-public/admin/wachtwoord-reset.php").read_text()
        helper = (ROOT / "private/admin_password_reset.php").read_text()
        self.assertIn("check_csrf()", src)
        self.assertIn("Referrer-Policy: no-referrer", src)
        self.assertIn("password_hash($password, PASSWORD_DEFAULT)", helper)
        self.assertIn("token_hash=NULL", helper)
    def test_manual_migration(self):
        sql = (ROOT / "database/admin_password_reset_migration.sql").read_text()
        self.assertIn("CREATE TABLE admin_password_resets", sql)
        self.assertIn("MANUAL MIGRATION", sql)

if __name__ == "__main__":
    unittest.main()
