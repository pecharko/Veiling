# Three-file visitor tracking repair — evidence and hard gates (2026-10-23)

**PREPARATION ONLY — NO PRODUCTION UPLOAD OR DATABASE WRITES**

## Confirmed read-only evidence

- GitHub Actions OVH inspection [run #39976186979](https://github.com/pecharko/Veiling/actions/runs/39976186979) found `private/visitor_stats.php` **absent**, production `catalogus-public/index.php` SHA256 `d6f5bf173c3fcb419727c33caeaab0bc6e7b54d19e6f78d9b6f716794c78ae27`, and production `catalogus-public/kavel.php` SHA256 `9c375dbcc23b26ac2fa00338f369d085faff3a91f7de1f283808db5197761668`; both production public files lack `track_catalog_view(`.
- Read-only historical GitHub comparison [run #39977460021](https://github.com/pecharko/Veiling/actions/runs/39977460021) matched **both** production public file SHA256s to source at commit `b5c3dd8ee122dad0af089d3502e4412d6684aad7`. Therefore historical source provides exact byte-equivalent old pages **at the inspection time**.
- Compared those historical pages to current main: index.php gains two tracking lines and one privacy footer link; kavel.php gains two tracking lines and one privacy footer link. No other source-line changes were identified. Preserve this privacy link; no admin page tracking.
- User supplied OVH phpMyAdmin screenshots showing all three tables in the selected database and `SELECT COUNT(*), COALESCE(SUM(views),0) FROM kobejoechris.visitor_stats_daily` returns **0 rows and 0 views**. The screenshot proves existence of the detailed table and absence of recorded views, **not** a full column/type/index inspection of all three tables.
- Existing live configuration inspector found `analytics_secret` as a literal 64-hex value (presence only; no secret exposed). Runtime resolution `cfg('analytics_secret')` was not tested.

## Deployment allowlist and exact ordering

Only: `private/visitor_stats.php`, `catalogus-public/index.php`, `catalogus-public/kavel.php`. Upload **helper first**, then homepage, then lot page, verifying each checksum. Never deploy the two public pages before the helper. No admin files, DB schema, private config, snapshots, or photos.

## Mandatory gates before any release approval

1. Complete read-only schema metadata query `docs/visitor_stats_schema_readonly.sql` against the correct OVH catalog database and verify each required column, primary key, types and indices with `scripts/validate_visitor_stats_schema_metadata.py`. A table list and a COUNT query are insufficient.
2. Execute isolated **tracking write** integration test with disposable MySQL, covering first visitor, repeat visit increment, separate visitors, same-day lots, DNT/no bot handling, missing key and DB exception handling, and admin exclusion. Existing archival MySQL tests are not tracking-write coverage. All CI must pass on exact PR head.
3. Refresh remote production SHA256/symlink/root/drift immediately before deployment: no changes from known current production digests or unexpected files. Verify exact immutable source SHA and PHP lint. No raw source or secret data logged.
4. Prepare **dedicated three-file manual dispatch release workflow** with plan/preflight and independently reviewed rollback policy. Do NOT run any existing six-file workflow for this release.
5. Separately obtain explicit release approval and then final deploy authorization. No automatic deployment and no merge-triggered SFTP actions.

This PR documents an evidence-based guarded candidate. **A merge does not mean deploy-ready.**
