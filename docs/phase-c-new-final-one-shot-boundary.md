# Phase C NEW — final one-shot sandbox worker boundary (2026-10-31)

## Current status: implementation ready for security review, NOT activated

The existing [PR #475](https://github.com/pecharko/Veiling/pull/475) remains **draft, unmerged, and unchanged**. This PR adds only local worker code, offline tests and this security contract. It does not run the worker, create an owner issue/comment, mint a token, create a nonce, move a sandbox ref, dispatch Actions, change permissions or write production.

### Immutable sandbox evidence

| Property | Exact value |
|---|---|
| Repository | `pecharko/Veiling` |
| PR | `475` (must stay open/draft/unmerged) |
| Protected ref | `refs/heads/test/phase-c-new-isolated-20261030` |
| Original head | `2ee5419b4642b2108414c8b9d4f193298004b23a` |
| Original blob | `76fda06da4013582f240ffc7c35ee94dbb502e67` |
| Only path | `tests/phase_c_new_repair_test.php` |
| Sole edit | `return flase;` to `return false;` |
| Prepared **unreferenced** candidate commit | `73be8a820e0801c9ceba3b548da91a5732325ec4` |
| Corrected blob | `3f48693b3092785554192ad766940565c9435a17` |
| Corrected content SHA-256 | `b743ff7e7006659676f24bf7626d95cb8a75a52b4c681e74a2629924d221059d` |

The GitHub candidate commit and tree are unattached Git objects; they do **not** advance the protected branch or alter draft PR #475. The compare of HEAD to candidate is exactly one commit, one changed test path, one insertion, one deletion.

### Isolation from the old experiment

The old sandbox PR #426 was already tested and its issue #427 nonce is **spent**. Never reuse issue #427, its nonce, old approval or historical worker, and never merge PR #426 or #475. The new worker cannot accept issue numbers <=477; future issue must be **new and owner-created**.

### Worker and gates

`scripts/phase_c_new_local_one_shot_worker.py` is a separate **local manual-only** implementation derived from the successful C2 isolated worker. Its normal mode is authenticated GET-only. Neither GitHub Actions nor the release controller invokes it. It has no CI credentials and no stored secrets.

- All operations are hard-pinned to the exact repository, head, candidate, path, expected blobs and digest; no generic file-edit, branch-selection or deployment feature.
- Authenticated App identity is checked against App ID `5526516`, installation `176547610`, slug `41lokeren-phase-c-writer`, exact selected repository and exact `contents:write`/`metadata:read`; **no permission escalation**.
- New owner issue body must be exactly `PHASE-C-NEW-PLAN pr=475 issue=<N> head=2ee5419b4642b2108414c8b9d4f193298004b23a blob=76fda06da4013582f240ffc7c35ee94dbb502e67`, authored by `pecharko` (numeric ID `343879284`).
- Owner's **separate, later comment** must have the exact `approval_phrase(<reviewed main worker commit>)` including issue, old/new commits, blob, path, nonce and worker SHA. A chat `go` or synthetic JSON cannot replace that authenticated issue comment or the explicit one-shot authorization.
- Locally checked-out code must be clean, on `main`, with its SHA identical to current remote `main`; any new main commit invalidates the approval.
- Rulesets #26113993 (both sandbox branches) and #26113833 (one-shot nonce tags) must be active with exact types, no bypass; after minting a short-lived repo-selected installation token, the **actual writer token** must independently pass the ruleset and absent-nonce checks or stop.
- Only one GraphQL `updateRefs` POST is permitted: atomic create-if-absent `refs/tags/phase-c-once-issue-<N>` bound to the old head and non-force CAS of only the protected PR #475 branch to the known candidate commit. No two-stage fallback, force push, broad GitHub POST or retry.
- On any uncertainty, network error, 403, conflict, missing receipt or changed PR/head: **STOP, NO RETRY**. Post-attempt independent readback checks refs, nonce, commit parent, exact compare, single file blob SHA/content digest and draft/unmerged status.
- Any successful receipt is an observed *sandbox* result only, not permission to merge PR #475, edit production or introduce an automated code-fixing service.

### Fastest safe remaining sequence

1. Review and merge **this development PR only** after exact CI and separate merge authorization; verify main and new push-CI. This is preparation, **not writer activation**.
2. Repository owner creates a new GitHub issue with the exact plan body shown above. The App credentials stay **local to the owner**, never in chat, GitHub Actions, issues, docs or logs. The owner can run GET-only mode with `--issue-number <N>`; it must return `READ_ONLY_VERIFIED` only when authenticated checks pass.
3. After manual security review of the **exact immutable main worker SHA**, owner adds the exact `approval_phrase` comment to the **same issue**. A **separate, precisely scoped live one-shot execution authorization** is still required. No `--execute` beforehand.
4. One local write attempt only; independently validate results and sandbox PR-CI, and publish an evidence-only closeout. Never retry after an attempted mutation, even after timeouts or conflicting results.

**Never perform OVH, database, deployment, workflowdispatch, ruleset edits, secrets changes, rights changes or any release operation as part of this exercise.**
