#!/usr/bin/env python3
"""Offline PHP cron compatibility guard: never execute project PHP or access OVH."""
from pathlib import Path
import re
ROOT = Path(__file__).resolve().parents[1]
cron = ROOT / "private/visitor_stats_daily_cron.php"
helper = ROOT / "private/visitor_stats_archive_one_day_experimental.php"
for f in (cron, helper):
    if not f.is_file() or f.is_symlink():
        raise SystemExit("Missing or unsafe PHP source: " + str(f))
code = cron.read_text()
archive = helper.read_text()
checks = {
 "CLI guard precedes bootstrap": code.find("PHP_SAPI !== 'cli'") > 0 and code.find("PHP_SAPI !== 'cli'") < code.find("require_once"),
 "7 UTC days retention": "strtotime('-7 days')" in code,
 "14 day catch-up limit": "$maxDays = 14;" in code,
 "explicit warning exit status": "exit(2);" in code.split("archive_catchup_limit_reached", 1)[-1][:180] if "archive_catchup_limit_reached" in code else False,
 "20k row ceiling in helper": "20000" in archive,
 "transactional archive": "$pdo->beginTransaction()" in archive and "$pdo->commit()" in archive and "$pdo->rollBack()" in archive,
}
for name, ok in checks.items():
 print(("PASS" if ok else "FAIL"), name)
if not all(checks.values()):
 raise SystemExit("Offline contract failed")
print("STATIC CONTRACT ONLY: PHP 8.5 runtime/SAPI, OVH relative path and DST remain unverified.")
