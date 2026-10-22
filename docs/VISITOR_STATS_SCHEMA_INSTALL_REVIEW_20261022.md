# Proposed initial MySQL 8.4 visitor statistics tables — review only

**Not approved for production execution.** The correctly selected OVH database returned zero metadata rows for all three target tables. This is evidence of missing tables at inspection time; repeat a read-only preflight immediately before any separately authorized installation.

## Source contracts reviewed
- `private/visitor_stats.php` writes one HMAC-SHA256 32-byte binary `visitor_id` per UTC day/page/lot, using `ON DUPLICATE KEY UPDATE views=views+1`, `lot_id=0` on home pages.
- `private/visitor_stats_archive_one_day_experimental.php` uses `FORCE INDEX(PRIMARY)` with `visit_date` as leading field; aggregates distinct visitor IDs, then deletes only source records after aggregate writes within a transaction.
- `catalogus-public/admin/statistieken.php` reads daily and day/lot totals, and supports authenticated manual archive/delete actions.

## Proposed DDL
`database/visitor_stats_install_proposed.sql` creates only the three explicitly named InnoDB tables, without `IF NOT EXISTS` (a pre-existing table must cause a hard STOP). Source `PRIMARY (visit_date, visitor_id, page_kind, lot_id)` ensures one row per day/visitor/type/lot and supports date-lock scanning. Archive tables use `PRIMARY (visit_date)` and `PRIMARY (visit_date, lot_id)` to reject duplicate historical aggregates. `visitor_id BINARY(32)` stores the full HMAC digest; no raw IP/user-agent is stored by this schema.

**No automatic migration or scheduled run.** Install statements are intentionally not wired into an OVH GitHub Action. Only an explicitly authorized person may execute DDL later in the confirmed target database; MySQL DDL implicitly commits and is not trivially reversible.

## Isolated tests
The new GitHub Actions workflow runs static checks and installs the proposed schema in a disposable `mysql:8.4` service with synthetic CI-only credentials. It verifies table count, source PRIMARY key column count and rejection of repeated installation. It never reads OVH secrets or connects to production. CI success is not approval to install production tables.

## Remaining gates
1. Independently review application contracts, types, indexes and MySQL 8.4 compatibility, including aggregate uniqueness. Review existing unrelated schema/migrations for conventions. No production DDL until review is complete.
2. Repeat read-only production schema check and confirm connection to the actual production database; STOP on any existing/mismatching object.
3. Explicit human approval naming the three CREATE TABLE statements, database, target and risk; separate from any PHP code deployment approval.
4. Plan safe DDL execution, backup/recovery, post-DDL metadata verification, and monitoring. DDL may not be transactionally reversible.
5. After separate confirmation, re-run live SFTP preflight for the two PHP installation targets, then use independent release/deploy authorization. Do not bundle this Draft PR into any production upload.

This PR must remain Draft, unmerged, and not executed on OVH.

## CI incident 2026-10-22
Original isolated MySQL workflow run 39766265069 failed **before connecting to disposable MySQL** in the static Python test: a malformed regex escaped an opening parenthesis incorrectly (`re.error: missing ), unterminated subpattern`) and its forbidden-keyword check wrongly included SQL comments containing the literal `IF NOT EXISTS`. Corrected only the Python test on the same Draft PR branch; the DDL was not altered. Prevent recurrence by running the offline test first, stripping SQL `--` comment lines for statement/keyword checks, and verifying the regex compiles before declaring MySQL compatibility. Production impact: none. Await fresh CI on the exact updated SHA.
