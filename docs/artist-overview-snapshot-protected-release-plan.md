# Artist overview snapshot: protected one-file release preparation

- Status: **DORMANT / NOT AUTHORISED FOR PRODUCTION**.
- Source: exact `main` commit `75c1769fdc2027635657502c881c4fd16103d2c5` from merged PR #605.
- Target: **only** `private/snapshots.php`.
- Release ID: `artist-overview-snapshot-20261116`.
- Source SHA-256: `3a847bc4006e3013cdca295192ef778c99f5e7fe389fb4816d6709924da46006`; derived from the immutable Git source and compared to the hash algorithm against the authenticated 2026-11-15 source.
- Expected OVH remote SHA-256: `ce1dcc6021ec2904474e57c710a5276cf4b08e40236b7515a749516eb40478d0`, last recorded as `VERIFIED private/snapshots.php` by successful protected run [43698852328](https://github.com/pecharko/Veiling/actions/runs/43698852328) at 2026-11-15. This is **historical runner readback**, not independent OVH attestation or a guarantee of current remote state. Check later deployments and fresh pinned-hostkey drift before any upload.
- Artifact references: protected run `43698852328` with rollback and evidence; original artifact retention expires 2026-11-22.

## Security boundary

The controller is offline/dormant and requires a separately authorized dedicated workflow called `artist-overview-snapshot-protected-release.yml`; that workflow is **not included in this PR**. The generic `shared-ovh-release.yml` explicitly forbids `private/snapshots.php`. Never invoke a historical fixed-ID release. An independent exact workflow/releasegate review is required before any workflow creation, merge, or dispatch.

A future dedicated protected workflow must enforce immutable `main`, exact owner confirmation and complete database **and photo** backup attestation, protected source allowlist, pinned SFTP hostkey, fresh remote drift compare, source backup, single-file upload, post-upload SHA-256 readback, rollback on failure, evidence retention, STOP/NO RETRY, and concurrency with other OVH production releases.

This packet does **not** authorize a workflow, SFTP write, database migration, snapshot creation, secret change, or deployment. Existing snapshots remain untouched. The owner's earlier statement confirms an external **database** backup, not a separately verified complete **photo** backup.

## Subsequent gates

1. Authorize and install the reviewed dedicated protected workflow (separate exact scope), and authorize this immutable one-file source deployment after fresh baseline / CI.
2. Only after successful snapshot-code release: separately authorize and execute the single additive `database/migrations/20261116_artist_overview_publication.sql`, verify schema and data preservation, using the owner's previously confirmed full database backup.
3. Only after migration: separately authorize two public/admin PHP files under `releases/artist-overview-toggle-20261116.json`.
4. Verify browser acceptance; keep independent OVH readback pending until separately attested.
