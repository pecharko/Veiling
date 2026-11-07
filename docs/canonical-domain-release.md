# Canonical catalogue domain — release boundary

Preferred host: `https://41lokeren.be` (no `www`). One development PR covers the HTTPS/www redirect and canonical hints for public, indexable catalogue pages.

## Requirements
- Apache `catalogus-public/.htaccess` issues a 301 from HTTP and from the `www` host to the HTTPS apex host. The original path and query string remain intact. No redirects depend on forwarded-host headers.
- Public index, numbered lot detail, previous-edition and Pecharko pages output one stable absolute canonical URL. Pagination keeps the page number; favourites/filter parameters do not create independent canonical variants.
- Privacy remains `noindex,nofollow`; admin pages and private preview state must not acquire a crawlable canonical URL.

## Protected deployment limitation
The existing guarded shared-OVH release uploader explicitly permits only `.php/.css/.js/.svg/.webp/.png/.jpg/.jpeg/.html` paths and rejects hidden path segments, including `.htaccess`. **Do not widen these guards or pretend this PR's HTTP redirect is deployed through the ordinary manifest.**

The branch provides `scripts/readonly_canonical_domain_baselines.py`, which only uses the existing hostkey-pinned SFTP reader and outputs SHA-256/ABSENT for the five target paths without downloading bytes into a repository artifact or writing to OVH. Run it only from a trusted, appropriately credentialed read-only environment; never place passwords, remote file contents or unverified hashes in the PR. Before any production write, separately establish authenticated, pinned read-only remote SHA-256 baselines of every target, including the existing `.htaccess`, and verify the current OVH mapping and SSL coverage of `www.41lokeren.be`. If the www hostname does not resolve/serve valid TLS, a redirect cannot fix the initial TLS failure; OVH DNS/SSL work is a separate owner-controlled approval boundary.

A specialized, owner-approved guarded release process must cover the `.htaccess` path while preserving the full code backup, drift protection, rollback, and post-write readback. The ordinary shared-OVH manifest may cover the PHP files only once live baselines are authenticated; do not invent or copy historical hashes. No automatic OVH upload, DNS/SSL change, releasegate alteration or database write is authorized by this development PR.

## Acceptance
- `http://41lokeren.be/...`, `http://www.41lokeren.be/...`, `https://www.41lokeren.be/...` return a direct 301 to `https://41lokeren.be/...`, preserving path and query.
- `https://41lokeren.be/...` is not redirected again.
- Public, indexable pages expose exactly one absolute canonical URL; preview remains non-indexable; privacy retains noindex.
- Verify the redirects from a public network (OVH WAF can block CI-based HTTPS checks) after the specifically approved deployment.

## Scoped release implementation prepared (inert without evidence)
`script/deploy` is not used: `scripts/deploy_canonical_domain.py` is a dedicated adapter that requires an exact schema-1 manifest at `releases/canonical-domain-20261107.json`, with exactly the five listed targets in order. The adapter does not relax the shared release engine's global denylist and fails closed while this manifest is absent. It delegates live pre-write drift checks, backups, remote verification and rollback to the existing engine. The offline `tests/deploy_canonical_domain_test.py` covers path restriction, missing baseline, source drift and confirmation denial.

To finish the same PR, obtain *independently authenticated* remote hashes via `scripts/readonly_canonical_domain_baselines.py`, inspect the live www TLS/DNS setup, create and validate the complete immutable manifest with verified source SHA-256 digests, and add a dedicated tightly gated GitHub workflow for exact-main authorized delivery. Do not activate a deploy workflow with incomplete or fabricated baseline evidence. Existing shared release controls remain unchanged. Production requires a second explicit owner decision after a separate approved PR merge and independent main CI.

## Dedicated deployment workflow
A separate, owner-gated `.github/workflows/canonical-domain-release.yml` has been prepared in the **same PR**. It checks the immutable current `main`, exact confirmation, full exact-scope source+baseline manifest, offline regression tests, and the existing pinned-SFTP release engine. It never automatically deploys on PR or push, and the general shared-OVH file allowlist is unchanged. Until the real baselines are authenticated and `releases/canonical-domain-20261107.json` is added to this PR, the deploy plan deliberately fails closed. No production authorization exists merely because the workflow is present.

## Security review hold: existing production .htaccess contents
The authentic SFTP probe (run 42353020298, main 29c2f309...) reports that `catalogus-public/.htaccess` is PRESENT with SHA-256 `defe7766ad2d82a1676ef63c40c2bdaf6c60ec62df9597577e640662e7626699`. The previous empty local Downloads copy is NOT production evidence. The proposed replacement currently includes only canonical redirect directives. **The specialized adapter now preserves the exact remote .htaccess bytes underneath the canonical preamble at deploy time instead of replacing them.** It requires the pinned authentic remote SHA, verifies it before constructing the combined payload, and the shared engine repeats drift verification, backs up every original byte, verifies upload, and rolls back when necessary. The existing Apache directives remain byte-for-byte intact below the new prefix. The actual deployed .htaccess digest is computed from that combined payload, not from the standalone canonical preamble stored in Git. Do not deploy until CI validates this specialized procedure, `www` TLS/DNS is confirmed and possible conflicting redirect behavior is reviewed. Also confirm that www DNS/TLS terminates successfully before any HTTPS redirect is deployed. No unapproved SFTP write, broad allowlist change, or workflow dispatch.

## Evidence for existing PHP baselines
Authenticated pinned read-only SFTP run 42352290221 from main against immutable source head `e8cf2cbac5d66a8235dd845c85be53df2ef6fb99` captured the four PHP source and remote digests in artifact `shared-ovh-readonly-42352290221`. Both this evidence and the protected .htaccess evidence are subject to fresh production driftchecks before any upload.

## Limited evidence-validator exception
The shared OVH evidence validator normally rejects filenames starting with a dot. The adapter temporarily applies the shared validator to an exact-path surrogate for the single `.htaccess` entry only, while requiring that the whole five-file evidence list match the authorized scope and retaining all SHA/rollback/status checks. It restores the original validator after the dedicated release. No global allowlist or evidence contract files are changed.

## Protected offsite backup gate
`.htaccess` is a production Apache configuration file. GitHub Actions artifact upload excludes dotfiles by default; do not change that to expose production configuration bytes in the public repository's artifacts. The shared engine retains the original .htaccess in the runner for in-run rollback but cannot provide a complete postrun external backup artifact for it. Before final production authorization, the owner must independently download the **current real** .htaccess through OVH and store it outside OVH with protected access. Verify the SHA-256 equals the authenticated pinned remote hash; the previous empty Downloads copy is NOT a backup. The deployment workflow now requires `protected_htaccess_backup_sha` and the adapter stops before any production connection unless it equals the pinned baseline. This hash attestation does not itself prove the backup bytes exist, so final owner authorization must attest to the external file's existence and integrity.
