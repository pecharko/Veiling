# One-review / one-release-authorisation shared OVH path (proposal)

## Why
The current chain (feature PR -> release-request PR -> release-manifest PR -> deployment authorisation) costs three independent merge approvals, extra `go` turns, and one production approval even for a single allowlisted PHP page.

## Target contract
For standard allowed shared-OVH code-only changes, the **feature PR itself** contains code, regression tests, and `releases/<id>.json`. The exact remote SHA-256 is acquired *read-only before merging*, the file's source hash is calculated on the PR head, and both are reviewed in the same PR. Thus one exact-scope merge decision and one exact-scope production decision, no release-request/manifest-PR approvals.

Owner may eventually elect **one combined approval**, but that requires a separately reviewed, owner-authenticated, one-use controller binding exact PR head+files+remote baseline to the ensuing immutable main commit and passing separate main push-CI. A skill instruction cannot create this authority. Until controller implementation is approved, **two substantive decisions remain**, not one.

## Current implementation in this PR
`scripts/premerge_shared_release_manifest.py` creates a manifest **offline** from read-only baseline evidence. It checks immutable evidence `head_sha`, schema, exact ordered allowlisted paths, current source SHA-256, remote SHA-256/ABSENT, duplicate/missing files and symlinks. Evidence with stale head or changed source fails closed. It never contacts OVH, merges or uploads. Regression tests cover success and tampering.

## Required follow-on integration before using this as the default
1. Run the existing SFTP **read-only** baseline collector against the PR's immutable candidate files, using **trusted main-branch scripts only** and a trusted workflow. Never execute code or workflow definitions from an unreviewed PR with OVH credentials. Attach `head_sha` and evidence to a CI artifact, and prevent forks and unauthorized source paths from touching the production environment.
2. Insert the generated exact SHA manifest into the **same code PR**, then re-run applicable CI and rebind immutable head: adding a manifest commit changes the PR head but not its code content. Evidence must bind a source-tree digest or code commit and explicitly track the manifest-only amendment; do not blindly reuse an earlier `head_sha`.
3. After explicit owner merge approval, verify merge commit contents and main push-CI; stop at one exact production approval. Keep `shared-ovh-release.yml` drift, hostkey, backup, rollback, one-use decision and post-upload readback intact.
4. Separate special paths (cron, private snapshots, auth, database, roles, release gates), multiple repos or concurrent overlapping code. No generic auto-merge or auto-deploy.
5. Amend `AGENTS.md` and the installed skill **only after** the trusted evidence-producing workflow and its security regression tests are green; do not present a proposal as activated automation.

## Important current limitation
This PR only delivers an offline verification component, not a credentialed premerge workflow or new merge/deploy authority. It cannot yet remove GitHub approval steps in live operation. The installed ChatGPT skill is distributed separately and is not automatically overwritten by this repository PR.
