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
