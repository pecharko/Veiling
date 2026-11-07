# Canonical catalogue domain — release boundary

Preferred host: `https://41lokeren.be` (no `www`). One development PR covers the HTTPS/www redirect and canonical hints for public, indexable catalogue pages.

## Requirements
- Apache `catalogus-public/.htaccess` issues a 301 from HTTP and from the `www` host to the HTTPS apex host. The original path and query string remain intact. No redirects depend on forwarded-host headers.
- Public index, numbered lot detail, previous-edition and Pecharko pages output one stable absolute canonical URL. Pagination keeps the page number; favourites/filter parameters do not create independent canonical variants.
- Privacy remains `noindex,nofollow`; admin pages and private preview state must not acquire a crawlable canonical URL.

## Protected deployment limitation
The existing guarded shared-OVH release uploader explicitly permits only `.php/.css/.js/.svg/.webp/.png/.jpg/.jpeg/.html` paths and rejects hidden path segments, including `.htaccess`. **Do not widen these guards or pretend this PR's HTTP redirect is deployed through the ordinary manifest.**

Before any production write, separately establish authenticated, pinned read-only remote SHA-256 baselines of every target, including the existing `.htaccess`, and verify the current OVH mapping and SSL coverage of `www.41lokeren.be`. If the www hostname does not resolve/serve valid TLS, a redirect cannot fix the initial TLS failure; OVH DNS/SSL work is a separate owner-controlled approval boundary.

A specialized, owner-approved guarded release process must cover the `.htaccess` path while preserving the full code backup, drift protection, rollback, and post-write readback. The ordinary shared-OVH manifest may cover the PHP files only once live baselines are authenticated; do not invent or copy historical hashes. No automatic OVH upload, DNS/SSL change, releasegate alteration or database write is authorized by this development PR.

## Acceptance
- `http://41lokeren.be/...`, `http://www.41lokeren.be/...`, `https://www.41lokeren.be/...` return a direct 301 to `https://41lokeren.be/...`, preserving path and query.
- `https://41lokeren.be/...` is not redirected again.
- Public, indexable pages expose exactly one absolute canonical URL; preview remains non-indexable; privacy retains noindex.
- Verify the redirects from a public network (OVH WAF can block CI-based HTTPS checks) after the specifically approved deployment.
