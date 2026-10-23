# Visitor statistics repair preflight — 23 October 2026

**PREPARATION ONLY. No production upload or database modification authorized.**

## Confirmed OVH read-only evidence

[Run 39976186979](https://github.com/pecharko/Veiling/actions/runs/39976186979):
- `private/visitor_stats.php` absent
- `catalogus-public/index.php` remote SHA256 `d6f5bf173c3fcb419727c33caeaab0bc6e7b54d19e6f78d9b6f716794c78ae27`, lacks tracking
- `catalogus-public/kavel.php` remote SHA256 `9c375dbcc23b26ac2fa00338f369d085faff3a91f7de1f283808db5197761668`, lacks tracking
- `analytics_secret` literal present and matches 64-hex format (not proof of runtime cfg resolution)
- MySQL not queried in the read-only SFTP run.

## Unresolved release gates — hard STOP before any upload

1. **Live schema and counts**: run read-only `docs/visitor-tracking-diagnostic-readonly.sql` using phpMyAdmin on the live selected catalog database, or another separately authorized read-only connection. Check `visitor_stats_daily` primary key and all columns; capture only table metadata and aggregate row counts. An empty table does not mean missing schema. Do not paste credentials or visitor IDs.
2. **Exact production diff**: the remote SHA256s prove a difference but not the exact changed lines. Compare the remote *bytes* of public `index.php` and `kavel.php` with the intended repository commit in a strictly read-only controlled process; do not log config or private secrets and do not publish live files. Review every existing production-only change and preserve it. Do not blindly overwrite.
3. **Integration CI**: run `python3 tests/visitor_stats_three_file_plan_test.py`, `python3 scripts/plan_visitor_stats_three_file.py`, `php -l` on all three candidates, and isolated MySQL integration on CI's disposable database. Confirm admin pages have no tracking invocation. Current existing archive-integration tests are not a substitute for a full tracker-write test.
4. **Release workflow**: existing six-file workflow is NOT suitable for the approved three-file allowlist. Prepare an independently reviewed dedicated three-file workflow *after* gates 1–3; any remote upload requires separate release approval, final deployment authorization, precise SHA and verified preflight/remote drift.

This PR creates only the offline plan and test. The candidate runtime PHP files themselves are **not changed**.
