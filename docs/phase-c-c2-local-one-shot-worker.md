# Phase C2 — local one-shot GitHub App worker (not activated)

This file documents a **manual, isolated sandbox-only** tool. It is not
connected to Actions, the general release controller or OVH, and it does not
change GitHub permissions, secrets, or repository settings.

## Preconditions (required, not currently claimed)

- Review the code and all negative tests on the **exact immutable PR head**.
- Merge requires separate security-scoped approval.
- Independently establish the existing GitHub App ID **5526516**, slug
  `41lokeren-phase-c-writer`, installation ID **176547610**, selected-only
  repository `pecharko/Veiling`, and exact contents:write / metadata:read.
- The owner must locally supply a **short-lived signed App JWT** in
  `PHASE_C_APP_JWT` and an **existing owner read token** in
  `PHASE_C_OWNER_READ_TOKEN`, exclusively as local environment variables.
  Never put either in PRs, Actions, chat, command arguments, logs or files.
- Read-only identity evidence for owner issue #427, approval comment
  #6493461101, PR #426, both rulesets, candidate commit, blob, old branch,
  and missing nonce must be acquired from authenticated GitHub responses.
- Default read-only probe, from a clean checked-out repository:

  `python3 -I scripts/phase_c_c2_local_one_shot_worker.py`

The probe does not mint an installation token and never makes an HTTP POST.
A refusal is a **hard stop**, not evidence of success.

## Future one-shot authorization boundary

Only **after review, merge and a new exact owner authorization** does
`--execute --approval-comment-id <new comment ID>` become a candidate for a
manual call. This does NOT authorize anyone to run it now.

The new comment must be posted **by pecharko on issue #427**, with the exact
single-line text returned by the function `approval_phrase(<locally checked out main SHA>)`.
That SHA must match a *clean* local Git checkout including the reviewed worker.
The old comment #6493461101 is insufficient for execution. A separate
explicit approval of the **exact one-time sandbox transaction** is required
in chat after the live preflight evidence has been reviewed.

In execute mode only, the code uses the existing App JWT to mint one
short-lived, repository-selected installation token, checks its scope, re-reads
the branch and absent nonce with that actual token, and sends **one**
GraphQL `updateRefs` POST:

1. Create the issue-specific, non-reusable nonce tag only if absent.
2. CAS the exact isolated sandbox branch from HEAD to the one-file candidate.

Both changes are a **single atomic transaction**, never force-pushed.
A rejected, timed-out or ambiguous attempt is **never retried**. After any
attempt the code performs independent owner-token GET readbacks and stops.
No fallback two-stage write, manual branch update, merge, tag deletion,
workflow dispatch, OVH or production access is included.

This executable implementation is dormant until explicit review and
authorization. Passing offline tests does not authenticate the GitHub App or
prove successful live operation.
