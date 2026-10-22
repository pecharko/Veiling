# Statistics one-day archive: NEW installation preflight (22 October 2026)

## Verified live status
The manual read-only OVH discovery reported `expected_status=absent`, `candidate_admin_files=[]`, `installation_classification=new_file_at_expected_path`. This proves that the expected admin target is absent, and that no similarly named PHP files exist immediately inside the verified admin directory. It does **not** prove anything about other directories or the new private helper path.

## Exactly two installation targets
1. `private/visitor_stats_archive_one_day_experimental.php` (first)
2. `catalogus-public/admin/statistieken.php` (second)

Source bytes must match the independently approved PR #81 merge (`a3237de3fde31b1cdf2141ccd596964f362263dd`); require PHP lint, relevant CI, immutable source SHA, and checksums. Use no directory sync, secret changes, `.htaccess`, snapshots, or DB changes.

## Read-only preflight gates
- Strict pinned OVH SFTP host key; validated expected application root via verified PHP markers.
- Use `lstat` on **both** targets. Each must be absent. Any existing object, including a symlink, must STOP; no overwrite path. Inspect again immediately before any separately approved installation.
- Check the required database tables `visitor_stats_daily`, `visitor_stats_day_totals`, `visitor_stats_lot_totals` via a separately authorized **read-only** metadata/schema query (e.g. `SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('visitor_stats_daily','visitor_stats_day_totals','visitor_stats_lot_totals')`). Verify all three, plus expected column compatibility before approving the release. Do not create tables; without evidence this gate is BLOCKED.
- The absence of an admin SHA-256 baseline is expected and **not** bypassing drift detection: both targets must remain absent. Do not set `OVH_STATS_ADMIN_BASELINE_SHA256` to a fabricated value.
- A green SFTP-only preflight **does not** imply DB gate success or release authorization.
- Proposed release profile: simple without rollback, **provisional**. Literal warning required during release approval: "Bij een fout is er geen automatische of voorbereide rollback; herstel vergt een nieuwe handmatige correctie of herdeploy en kan downtime veroorzaken."
- Separate explicit release approval with the exact SHA/paths and risk acceptance, then separate final deploy authorization. Deploy only with manual workflow_dispatch and allowlisted source hashes; record remote sha256 after each uploaded file, then read-only HTTPS smoke tests. No deployments authorized by this PR.

## Incident prevention
Earlier SHA-256 observation runs failed because they expected an existing admin page, while the live file did not exist. The later read-only directory observation distinguished absence from drift. Future release tooling must classify existence BEFORE asking for an existing-file baseline.
