# Photo impression release: CI recovery record

The photo feature was reviewed and explicitly merged through PR #531, head
`0170ebe232164c4a4ea550ffe95b53a46d5cf1b8`, into main
`db8ee5094902375f23417c94bd014faf7d9ed0e3`. All 27 approved blobs and the eight
infrastructure blobs from PR #532 were independently matched in the merge tree.
No photo feature files have been uploaded to OVH.

The immutable release manifest remains
`releases/previous-edition-impression-20261102.json`, SHA-256
`a621fa29861c2823dc02eca8762455d04fb12d1b907fe0b3dd094d8b126a4c53`.
It contains the same 22 runtime paths: 21 new files and the homepage. Authenticated
read-only run `41458355338` measured those exact paths from candidate
`99877e40c49e08f4a58e76956d06327f7584f635`, using trusted main
`abb04471cddda20f8b78b31e54f2654ac9dfe4af`. All source hashes, log rows, artifact
bytes and the archive digest were checked. The new files were observed ABSENT;
the homepage remote hash was
`8c7039f02a7f92196e34621d34af3c4a1b36b9b0ce6f84395b757b61b3f97e29`.

Independent push-CI for the feature merge:

- PHP/repository run `41459719498`: successful.
- Owner-bound offline safety run `41459719494`: successful.
- Claim/Mac run `41459719488`: isolated MySQL job successful. Mac job
  `125325354659` was cancelled after fifteen minutes with an empty runner name
  and no steps; its tests never started. The overall first attempt failed.

The failed-job retry was requested before checking the controller's first-attempt
restriction. That retry is attempt 2 and cannot serve as deployment evidence,
regardless of its eventual result. `owner_bound_dispatch._repo_run` intentionally
requires `run_attempt == 1` for the required push-CI runs. The original failed
run must remain recorded; no history, gate, runner requirement or approval is
removed or relaxed.

This documentation-only recovery proposal changes no runtime file, release
manifest, test, workflow, permission or credential. After a separately authorized
merge, the resulting new immutable main SHA must receive successful first-attempt
push-CI for all three required workflows. Only then may the distinct production
authorization be requested, bound to that resulting SHA, the unchanged manifest,
its exact source/remote hashes and a fresh nonce. No production consent, dispatch
or journal reservation has occurred. The unused draft nonce for the earlier main
was superseded and must not be used.

The existing Mac journal and completed releases remain untouched. The existing
freshness limit, pinned SFTP hostkey, complete pre-upload drift check, backup,
rollback, independent readback and browser acceptance remain required. No release
retry or new read-only production scan is authorized by this recovery record.
