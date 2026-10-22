# OVH statistics: aggregate-only DB installation review (2026-10-22)

## Observed production
The supplied phpMyAdmin screenshot in database `kobejoechris` confirms `visitor_stats_daily` exists with `visit_date DATE`, `visitor_id BINARY(32)`, `page_kind VARCHAR(8)`, `lot_id BIGINT UNSIGNED DEFAULT 0`, and `views INT UNSIGNED DEFAULT 1`. Its PRIMARY index is `(visit_date, visitor_id, page_kind, lot_id)`; a secondary `idx_lot_top` index is `(visit_date, page_kind, lot_id)`. This remains untouched. `visitor_stats_day_totals` and `visitor_stats_lot_totals` were not visible in the supplied tree; independently recheck both immediately before DDL.

## SQL proposal and safety
`database/visitor_stats_install_proposed.sql` now contains EXACTLY TWO `CREATE TABLE` statements, for `visitor_stats_day_totals` and `visitor_stats_lot_totals`. No `CREATE`/`ALTER`/`DROP`/DML targets `visitor_stats_daily`. Neither a conditional `IF NOT EXISTS` nor an overwrite is used: existing aggregate tables cause STOP. Each aggregate uses a date-leading unique PRIMARY key, as expected by `private/visitor_stats_archive_one_day_experimental.php` and `catalogus-public/admin/statistieken.php`.

## Compatibility assessment
The live daily table's `VARCHAR(8)` stores `home` and `lot` consumed by the existing PHP; its `INT UNSIGNED` works with the PHP's summation and increments but has a lower maximum than `BIGINT` (monitor potential overflow). Its date-leading PRIMARY index supports the archive `FORCE INDEX(PRIMARY)` and day-range lock scans. The existing `idx_lot_top` has a different order than the old proposed index; this affects possible query plans, not correctness. Confirm performance/constraints separately if needed. The new aggregate types are `BIGINT UNSIGNED` for counts/views and compatible with `COUNT(DISTINCT ...)` and `SUM(views)`.

## Regression tests
Offline contract asserts there are exactly two DDL statements and no daily-table changes. A GitHub Actions **disposable MySQL 8.4** service recreates the observed live daily column and index types, inserts a synthetic visitor row, applies the aggregate-only SQL, confirms the row/type remain intact, confirms all three tables, and ensures repeat installation fails without losing the synthetic row. CI never connects to OVH.

## Mandatory production gates
1. Read-only verify the active database is `kobejoechris`, that `visitor_stats_daily` remains structurally compatible, and that *both* aggregate tables are absent.
2. Have a verified data backup and a reviewed recovery plan: MySQL `CREATE TABLE` implicitly commits, so partial DDL changes cannot be rolled back as one transaction.
3. Obtain explicit database-specific authorization for precisely the TWO aggregate CREATE statements. A PR merge never authorizes database modification.
4. Only after a separately authorized manual DDL step, verify columns and indexes read-only. Re-run the SFTP preflight and approve the two-file PHP release separately.
5. Never run the obsolete three-table SQL from previous git revisions against OVH.

No deployment/merge/production SQL was executed by this PR.
