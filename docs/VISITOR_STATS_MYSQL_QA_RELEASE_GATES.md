# Review MySQL archive integration, privacy and deployment gates

**Status:** TEST/PR only; no production database operations, no upload authorization.

## Isolated MySQL testing

New `.github/workflows/visitor-stats-mysql-integration.yml` runs tests against a disposable MySQL 8.4 service named `visitor_stats_ci`, using CI-only credentials. `tests/visitor_stats_mysql_integration.php` explicitly refuses DSNs without `mysql:` and `dbname=visitor_stats_ci`. It creates three tables only in that disposable database, inserts fixtures and checks: daily totals, lot totals, unique visitor-days, idempotent second archival, recent-day preservation, fail-closed on aggregate conflict, transaction rollback, and invalid date refusal.

These tests **have not been proven green until GitHub Actions completes**. Source invariants and MySQL behavior are separate from production verification. Hostkey, drift, OVH database quota and php/PDO server versions still require review.

## Privacy wording

`catalogus-public/privacy.php` now states that older data is **manually** archived, and that codes persist longer if the administrator fails to execute the manual process. Aggregated campaign results have stated end date 31 October 2027. `scripts/plan_visitor_stats_six_release.py` and its privacy assertions are aligned; the six-file release is **not** authorization for the new schema + archiving deployment.

The public privacy text still says the legal basis/proportionality requires review. Explicitly do not set workflow field `PRIVACY REVIEW APPROVED` until a real positive assessment, actual legal identity, and a documented deletion operator exist. The optionality/manual nature of deletion means a concrete reminder/operating process is required, not only code.

## Risk checklist before release

- [ ] All isolated MySQL and regular CI controls passed on exact PR head and exact merged SHA.
- [ ] Fresh, verified private backup and isolated recovery check for live MySQL before schema operations; exact file allowlist and no data loss.
- [ ] Inspect schema and storage limit (250 MB) read-only before adding two aggregate tables; verify privileges and charset.
- [ ] Confirm date cutoff and memory upper bounds (archive currently buffers all old data into PHP memory; batching may be needed for scale).
- [ ] Validate transactions under concurrent visits and malformed records, and audit data access permissions.
- [ ] Confirm manual operator to archive at most every 30 days, no automatic cleanup/cron.
- [ ] Confirm total deletion after 31 Oct 2027 and tracking stopped before 1 Nov 2027.
- [ ] **Important:** kavel-pair overlap metrics are not preserved by present aggregations; these cannot be reconstructed once codes have been removed.
- [ ] Complete AVG legitimate-interest assessment and transparent rights/objection handling.
- [ ] New deployment workflow and SQL migration run **separately** from legacy six-file code-only release, with distinct explicit authorization stages.

**No production changes performed by this PR.**
