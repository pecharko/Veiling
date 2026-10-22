# Visitor statistics two-file code release (draft only)

- Website: https://41lokeren.be
- Source pinned to `9af7316fee95dab005fd8ca565f1f399f5c14918`
- Only `private/visitor_stats_archive_one_day_experimental.php` and `catalogus-public/admin/statistieken.php`.
- Manual `workflow_dispatch`; default `plan`, requires exact pinned SHA; deploy additionally requires a separate confirmation phrase and production environment gate.
- Reuses pinned-hostkey SFTP connector and validates root markers; checks BOTH remote targets absent and uses SFTP exclusive-create mode at the instant of each write, then remote read-back SHA-256 and size/type checks. Stops immediately at any failure. No overwrite, symlinks, delete, cleanup, backup artifact or rollback.
- Source code checked out at the immutable approved commit. Deployment helper checked out from main after this reviewed PR is merged. Required Python compile, PHP 8.2 lint, offline plan. Read-only HTTPS smoke tests following deploy, never archival/deletion requests.
- Existing read-only OVH preflight succeeded in run 39786865007, but it is time-specific; the upload script rechecks target absence immediately before writing. DB schema was separately verified by phpMyAdmin, not the remote preflight.
- **Release profile: explicitly selected simple without rollback.** Bij een fout is er geen automatische of voorbereide rollback; herstel vergt een nieuwe handmatige correctie of herdeploy en kan downtime veroorzaken.
- Risk: an SFTP connection interruption can leave a partially created target and prevent safe retry; STOP, inspect manually, never auto-remove. Existing data-only snapshot excludes analytics. This upload does not modify database data; authenticated archive/delete features must not be exercised during smoke tests.
- Before merge, review script/workflow and inspect exact diff; require all CI and dedicated syntax/static tests. After merge, run plan first and request separate final deploy authorization. **Neither merging nor plan grants upload permission.**

No production operation has been triggered by this draft.
