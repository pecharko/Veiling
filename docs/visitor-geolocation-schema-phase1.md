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
