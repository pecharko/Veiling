# Statistics page: live absence and installation classification (2026-10-22)

## Verified evidence
- The existing manual, pinned-hostkey, read-only OVH workflow successfully validated the two known application anchor files `private/bootstrap.php` and `catalogus-public/admin/index.php`.
- It returned `Required remote path missing: catalogus-public/admin/statistieken.php`.
- The GitHub `main` tree contains `catalogus-public/admin/statistieken.php` and `private/visitor_stats_archive_one_day_experimental.php` as proposed release sources.
- Therefore this is **not an in-place update of an existing file at the expected live path**. Whether another live page contains equivalent functionality remains UNKNOWN until the restricted filename discovery runs.

## Minimal read-only follow-up
- This PR changes only the existing manual SHA-256 discovery script; no workflow changes and no production access during PR tests.
- If the expected file is absent, enumerate **only names** (never content) of regular PHP files whose names contain `statist` or `analytic` directly inside the already validated `catalogus-public/admin` directory.
- Do not traverse parents, private, uploads, snapshots or arbitrary directories. Do not log the account root.
- If a candidate exists, stop and review whether it contains equivalent functionality; never overwrite it without separate approval.
- If no candidate exists, classify as **new installation at the expected path**, not overwrite. The existing production preflight and release instructions that assume an existing SHA256 baseline must be amended before any release.
- Additional release gates remain blocking: check the existing three MySQL aggregation tables read-only; both remote target paths absent; SFTP root and hostkey; test dependencies and PHP syntax; exact pinned source SHA and allowlist; separate release and final deploy authorizations. No database changes.
- The simple/no-rollback profile remains provisional; this investigative PR is never permission to deploy.

## Failure lesson
Symptom: the baseline workflow failed on `[Errno 2] No such file`, and later more precise diagnostics identified the missing expected admin stats file. Cause: release preflight incorrectly assumed that admin stats already existed on OVH. Prevention: classify target as absent vs existing **before** requesting a baseline SHA; use a small read-only allowlisted directory discovery instead of guessing an alternative path or silently bypassing drift checks.
