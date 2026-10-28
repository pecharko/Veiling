from pathlib import Path
import unittest
s=(Path(__file__).resolve().parents[1]/"catalogus-public/admin/mailtest-eenmalig.php").read_text()
class MailProbe(unittest.TestCase):
 def test_guards(self):
  for x in ["require_administrator()", "require_no_index()", "check_csrf()", "fopen($path, 'x')", "VERSTUUR-EEN-TESTMAIL", "chris.pecharko@gmail.com", "noreply@41lokeren.be"]:
   self.assertIn(x,s)
 def test_no_autosend(self):
  self.assertIn("if (is_post())",s)
if __name__ == "__main__":unittest.main()
