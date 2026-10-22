# OVH statistics schema gate — strictly read-only

**Status:** Prepared only, NOT executed against OVH. No credentials or production database connections in GitHub Actions.

1. In OVH phpMyAdmin, select the **actual production catalog database**. Run exactly the metadata-only SELECT in [visitor_stats_schema_readonly.sql](visitor_stats_schema_readonly.sql). This query accesses `information_schema.COLUMNS` only. Do not run SQL inserts, creates, updates, deletes, alters or selects of visitor data.
2. Review the returned table/column/data_type/column_key values. Convert the metadata rows to a JSON list of objects with exactly `table_name`, `column_name`, `data_type`, `column_key` (lowercase keys), and no database/host/user/password or visitor data.
3. After this PR is separately reviewed and merged, use GitHub Actions `Visitor stats schema metadata validation (offline)` on `main` with `schema_columns_json`. The PR itself only runs offline synthetic tests. Do not pretend this verifies provenance: independently attest the JSON is from live OVH.
4. The validator fails closed if any of the three tables or required columns are missing, if `visit_date` is not DATE, if numerical aggregation columns are not integers, or if `visitor_stats_daily.visit_date` is not a member of PRIMARY KEY. The result `SCHEMA_STRUCTURE_MATCH` is **structure-only**, not a production-release authorization.
5. Before release, review precise PRIMARY-key ordering, uniqueness of aggregate keys, nullable/default compatibility and source/admin PHP queries. These are not asserted in this narrow first-pass validator. Schema drift, unverified origin or missing privileges means STOP and manual review.

Production deployment remains prohibited without independent signed-off DB schema compatibility, freshly successful SFTP absence preflight, pinning of release source, required CI and PHP lint, explicit release approval with rollback-risk acceptance, and separate final deploy authority. No DB modifications.
