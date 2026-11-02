# Read-only baselines for new candidate files

This infrastructure proposal must be reviewed and explicitly merged before
activation. No production upload, workflow dispatch or feature merge is authorized
by this document. The photo feature remains in PR #531; its manifest belongs in
that same feature PR after authentic baseline collection.

The existing `shared-ovh-readonly-preflight.yml` still runs only from `main`, with
the production environment, pinned SFTP hostkey and unchanged `contents: read`
permission. Its optional `source_sha` input accepts exactly one lowercase 40-digit
commit SHA. Empty input preserves the existing main-source behaviour and artifact
shape. A candidate is fetched as Git data without checkout. Only allowlisted,
nonempty regular blobs up to 8 MiB are read; symlinks, directories, expression
refs and missing files fail before SFTP. No candidate PHP, Python, workflow,
dependency or Git hook is executed. Candidate files need not exist on main.

The SFTP measurement still reads each exact remote path, reports `ABSENT` only for
ENOENT, fails on other errors and never opens a remote file for writing. The
single retained JSON artifact contains `schema`, `files`, and, only in candidate
mode, `head_sha`. The artifact name and seven-day retention remain unchanged.

The Mac evidence reader authenticates the first successful main workflow run,
repository, immutable trusted-run SHA, completion before owner approval, artifact
digest, inventory and archive members as before. It independently fetches candidate
file bytes at the artifact's `head_sha`. The offline adapter checks those hashes
against the artifact AND the explicitly approved files on the resulting main.
Candidate content drift is therefore rejected. Legacy baselines remain supported.
No owner text, nonce, journal, dispatch, freshness, backup, rollback or production
drift check is relaxed or added to this proposal.

After an exact infrastructure merge approval and verified main-push CI, obtain
separate authorization for one pinned read-only scan. Use workflow branch `main`,
`source_sha=99877e40c49e08f4a58e76956d06327f7584f635` for the reviewed photo snapshot,
and `files` containing exactly its 22 runtime paths (index, page, CSS, JS and the
18 `catalogus-public/assets/vorige-editie-*.webp` variants). Recheck the current
feature diff before any launch; do not run this instruction on a changed feature.

Authenticate the resulting run and artifact independently. Use the existing
`premerge_shared_release_manifest.py` with that exact source head and source bytes
to assemble the real manifest. Add it to PR #531 and rerun its final CI. The
infrastructure and feature have separate exact merge decisions because the former
changes trusted release evidence processing. Production authorization remains a
separate decision after the feature merge and independent main-push CI.

The Mac must use a checkout containing this reviewed evidence-reader update
before reviewing a candidate-baseline release. Existing completed claims and
nonces must never be cleared or reused.
