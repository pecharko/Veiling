# Protected snapshot reset deployment plan

This request is **read-only preparation**. It does not authorize or perform an OVH upload.

- Target: exactly `private/snapshots.php`.
- OVH SHA-256 observed in read-only GitHub run 40660226005: `181eba2f8954a58a4d562c0f9d4b086a16c0db97eefd4b411a3ba0b20d3f68f7`.
- Repository source SHA-256 observed in that run: `eba4d6f3e1ec958acc12e91397635589d09077d475ab192f4538ff413c0c22d8`.
- Recheck both against the immutable reviewed commit and read-only remote evidence just before a separate exact production approval.
- Existing generic preflight and uploader intentionally prohibit `private/snapshots.php`: **do not relax this protection**. A separately reviewed, single-target guarded upload path must verify source and remote SHA before writing, preserve remote original for 7 days, verify uploaded byte content, attempt rollback on failure, and emit auditable evidence.
- Coordinate with reset DB table confirmed by operator, four-file manifest `admin-password-reset-combined-20261028`, and separate temporary mailtest retirement workflow. These are distinct operations under a single functional acceptance, each subject to its own exact authorization.
- Test isolated snapshot restore invalidates outstanding reset tokens; no automatic DB migrations, mailing, dispatch, or production writes.
