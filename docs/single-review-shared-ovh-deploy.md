# Single-PR shared OVH deployment — safe standard procedure

## Objective
For a routine allowlisted web file, use **one code-and-manifest PR, one scoped merge approval, one scoped production approval, zero `go` prompts**. Do not manufacture three PRs by default.

## Operational stopcheck before closing a turn (issue #23)

**Do not end on a progress milestone.** Before a final message, identify the next safe action and execute it in the active turn when allowed: complete development, inspect CI, correct in-scope regressions, verify exact PR diff, mark ready for review, inspect GitHub read-only evidence, or inspect immutable main/push-CI after an exact authorized merge. Keep owner approval boundaries intact. If CI remains pending beyond bounded checks, report the exact pending run; never call that a request for `go` and never promise asynchronous completion.

The 19 November 2026 admin-session idle issue exposed two avoidable interruptions:
- Development/CI/security review was divided into turns with unnecessary `go` prompts although the next read-only/in-scope step could proceed.
- The runtime PR #626 was merged before the remote baselines were gathered and the manifest prepared. A separate manifest-only PR #627 then required an extra exact merge approval. This was an exceptional recovery path, **not** the default one-PR procedure. For auth/private releases keep specialized review gates even if a one-PR bundle is possible.

For each stop, identify its **exact type** instead of requesting `go`:
1. **Owner authority:** exact SHA+scope squash merge or a separate production release authorization.
2. **Tool dependency:** one precise manual Actions launch (read-only preflight or *already authorized* deployment) only when no permitted dispatch tool exists; `gestart` is a signal to fetch the run and keep going.
3. **Security gate:** protected auth/DB/cron review, failed hostkey/drift/backup, ambiguous write (STOP, NO RETRY), missing trustworthy evidence.
4. **Owner acceptance:** actual browser/function test after deployment when necessary.
5. **External processing:** report the run/status when it cannot complete in the same active turn; do not imply that background work is running within ChatGPT.

### Fictional offline rehearsal (no GitHub/OVH execution)

The `veilingdeploy2-codex` skill includes `scripts/simulate_release_handoff.py`, a deterministic **offline model**. It was run in eight scenarios (all passed): straight-through success with zero ritual-go prompts and two exact approvals; generic `go` grants no authority; recoverable CI failure repairs within the same execution; absent baseline requires one mechanical read-only start; protected auth review remains mandatory; wrong head/main rejects approval; drift stops before upload; and a deploy workflow start without connector support remains manual. The model verifies only stop logic, **not** production controller, permissions, OVH hostkey, CI, or user-session behavior.

## One-PR implementation (available without new releasegate permissions)
1. On the isolated development branch, implement the full code change and targeted tests.
2. Obtain the *observed live baseline* by examining the **most recent successful, exact-main GitHub Actions deployment** of every target file: inspect its workflow_dispatch identity, the exact `VERIFIED <file> <sha256>` post-upload readback, and retained evidence/rollback artifacts. That attested readback hash may be used as the expected remote SHA in the new manifest. A historical manifest, `SOURCE` line, green CI or user report **alone** does not prove remote state.
3. If no unambiguous per-file verified readback exists, perform the existing **read-only SFTP preflight** (trusted scripts and pinned hostkey). Never guess a remote baseline or use `ABSENT` without evidence.
4. Compute SHA-256 of the exact candidate source. Create `releases/<id>.json` **in the same development PR** as the changed code and tests, with exactly those source and verified-remote hashes. Do not open a separate `release-requests/` or manifest PR for this routine case. Only use `scripts/premerge_shared_release_manifest.py` with exact-head read-only evidence when a fresh scan is needed.
5. Run regression tests and full applicable PR CI **after the manifest addition**. Check the final immutable PR head, complete diff, source SHA, branch compatibility, and any overlapping active releases. Obtain **one explicit, exact-scope PR merge approval** under `AGENTS.md`.
6. Merge, verify new immutable main commit, exact tree contents and **separate main push-CI**. Prepare one exact production approval binding the **new actual main SHA**, release ID, every source+remote hash, authorized file list and drift/backup/rollback/readback obligations.
7. Only with that second explicit approval run the unchanged `shared-ovh-release.yml`. Its **fresh remote SHA/drift check immediately before upload** remains decisive; if any drift, abort. Check evidence, rollback and VERIFIED hashes independently, then ask only missing browser acceptance.

## Preconditions for reusing a previous verified readback
- It is an actual success `workflow_dispatch` of the existing guarded release, not a proposal or test; the log shows `VERIFIED <exact path> <SHA>`, plus evidence and rollback artifacts.
- Subsequent verified release runs modifying that same file must be accounted for, and any known manual remote modification, suspicious drift or uncertain write means **require fresh read-only SFTP** instead.
- The baseline is an optimistic expected SHA, *not* permission to skip live drift verification. If OVH has changed since that run, deployment fails closed without touching the changed file.
- No use of older `snapshot` state or raw production user data. No automatic remote write or secret exposure in development CI.

## Exceptions: use existing specialized tracks
Cron or scheduled jobs, database and schema, snapshot/restore, secrets, roles/auth, hosting configuration, releasegates, protected paths, unclear provenance, mixed release scopes and overlapping server writes keep their individual safety and authorization gates. Routine procedure must never expand the shared writer allowlist.

## Separate future goal — one total approval
Merging and deployment currently require independent exact authorizations; their respective immutable SHAs are not the same. One combined pre-merge owner decision requires a **new**, separately reviewed one-use owner-authenticated controller that cryptographically binds the reviewed PR tree and manifest to the *resulting* main SHA and checks main push-CI before dispatch. Do **not** interpret a generic `deploy` as that authority, and do not weaken `AGENTS.md` or production confirmation checks to simulate it.

## Offline helper
`scripts/premerge_shared_release_manifest.py` validates an immutable-head, trusted read-only evidence JSON against on-disk source content and exact path allowlists; tests cover mismatch, tampering and protected paths. It does **not** prove GitHub evidence provenance or perform any OVH action. Always verify evidence authenticity independently.
