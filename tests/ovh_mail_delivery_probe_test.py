"""Static safety checks: probe must never send mail during CI."""
from pathlib import Path
import unittest
ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "scripts/ovh_mail_delivery_probe.php"

class MailProbeTest(unittest.TestCase):
    def test_cli_only_and_single_attempt(self):
        s = SOURCE.read_text()
        for required in ("PHP_SAPI !== 'cli'", "'SEND-ONE-41LOKEREN-TEST'",
                         "flock($handle, LOCK_EX)", ".ovh-mail-probe-20261028.used",
                         "private/config.php", "fstat($handle)['size'] > 0"):
            self.assertIn(required, s)
    def test_fixed_mail_target(self):
        s = SOURCE.read_text()
        self.assertIn("'chris.pecharko@gmail.com'", s)
        self.assertIn("noreply@41lokeren.be", s)
        self.assertIn("PHP mail() accepted message; delivery not yet proven.", s)

if __name__ == "__main__":
    unittest.main()
