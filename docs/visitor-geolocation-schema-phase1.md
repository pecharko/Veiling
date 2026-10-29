# Geographic analytics phase 1: proposed database contract

This is an **unexecuted migration proposal**. Running this schema alone will not enable geographic tracking; no collection, dashboard, or archive changes ship in this phase.

- `visitor_geo_daily`: at most one row per (UTC day, pseudonymous visitor id). Repeated visits increment views. One daily visitor is assigned one geo bucket. Never store raw IP or browser agent.
- `visitor_geo_day_totals`: daily aggregate keyed by country, region and city. Consolidation MUST be in the same DB transaction as deleting the corresponding geo detail rows, after verifying archive counts. Implement a migration-aware cron guard before activating writes.
- Country `ZZ`, blank region and blank city mean unknown, not a known location.
- GeoLite2 City reader + locally provisioned .mmdb is a separate dependency; do not call remote geolocation services per visitor.
- Any mismatch between old archive and new geo-detail retention is an activation blocker.
- Update snapshot export, restore, wipe, reporting, privacy and retention before deploying the feature.
- Database quota, geo data file placement, backup size and privacy implications need read-only preflight.
- User decided that legacy snapshots need not be restorable *after* migration, but new snapshots must be restorable.
- Explicit scoped permission is mandatory before executing SQL or uploading runtime PHP.

## Stage-only transaction-bound collection seam

`private/visitor_geo_record.php` provides `visitor_geo_record_view()` but is not called by the live visitor tracker. Its 32-byte daily identifier must be exactly the existing tracker identifier; the duplicate-key update increments page views while preserving one geographic visitor-day. The eventual tracker must write existing and geographic counters in the **same** transaction, so a failed geo write cannot skew totals. An approved migration, geolocation reader installation, privacy update, snapshot/restore verification and archive/cron integration are prerequisites to enabling collection.

The proposed geo archive helper is not yet connected to either the manual archive or issue224 cron; **do not activate geo collection or deploy these files** until both archive paths preserve geo totals and the deployment order is validated. `tests/visitor_geo_record_test.php` exercises isolated SQLite transaction/deduplication guards; MySQL integration is still required before activation.

## Fail-closed archive guard (not activated)

`private/visitor_geo_archive_guard.php` compares distinct visitor-days and total page views between `visitor_stats_daily` and `visitor_geo_daily` for a single UTC date within an existing transaction. A mismatch aborts archiving rather than silently losing location data. The function is deliberately not wired into the manual or issue224 cron archive because legacy days before geo activation require an explicit migration decision, and concurrent locking guarantees must be reviewed. No production archive is changed. `tests/visitor_geo_archive_guard_test.php` checks mismatch rejection with an isolated fixture; live MySQL locking and transaction verification remain required.

## Geographic dashboard presentation: percentages only

The admin geographic breakdown must show **percentages, never absolute visitor-day or page-view numbers**. Use `visitor_geo_group()` followed by `visitor_geo_percentages()` for each country / region / city breakdown. Each grouping uses the sum of visitor-days in all its buckets as the denominator, including unknown country (`ZZ`) and missing region/city (`?`), so percentages are comparable within that view. Round to one decimal place; rounding can make displayed percentages differ from 100.0% by a tenth. Internal counts remain in the database for archival integrity only and are never displayed in the geographical section. State explicitly that a visitor-day is not necessarily a unique person. The dashboard is not connected yet; this requirement must be enforced when implementing it.

## Staged percentage-only admin markup

`private/visitor_geo_render.php` renders an escaped two-column geography table (origin and share) strictly from `visitor_geo_percentages()` output. It rejects arrays containing absolute `visitor_days` or `views` and supports an explicit empty state. `tests/visitor_geo_render_test.php` covers percentage formatting, HTML escaping and rejection of absolute data. The renderer is not included by the live admin until the geo schema, data collection, 3-day archive/cron, retention and snapshot/restore have been approved together. Do not expose zero/partial geo coverage as if it represented the complete audience.
