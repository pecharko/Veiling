# Phase C NEW sandbox: inert trusted-writer boundary (2026-10-30)

**Status: development proposal only. NO live writer activation, authorization, token, nonce, or GitHub mutation.**

## Immutable scope
- Repository `pecharko/Veiling`; starting main `3a0d61c07832df05eba4f377f446fd9e98b4f9be`.
- Independent draft [PR #475](https://github.com/pecharko/Veiling/pull/475), never merge; branch `refs/heads/test/phase-c-new-isolated-20261030`, head `2ee5419b4642b2108414c8b9d4f193298004b23a`.
- Only fixture `tests/phase_c_new_repair_test.php`; old blob `76fda06da4013582f240ffc7c35ee94dbb502e67`.
- Sole proposed (NOT executed) byte edit: `return flase;` to `return false;`. Result blob `3f48693b3092785554192ad766940565c9435a17`; SHA-256 `b743ff7e7006659676f24bf7626d95cb8a75a52b4c681e74a2629924d221059d`.
- Branch ruleset #26113993 must stay active, with BOTH isolated branches, deletion/non-fast-forward rules and no bypass. Tag ruleset #26113833 must stay active and prevent nonce update/deletion without bypass.
- Previous PR #426 and issue #427 are closed for writer use; their nonce is already spent. Never rerun or merge the prior exercise.

## Implementation

`scripts/phase_c_new_trusted_writer.py` contains deterministic byte/hash verification, an exact snapshot/owner-shaped evidence validator, an inert two-ref atomic `updateRefs` review envelope, and a purely in-memory CAS simulator. Every review result has `write_allowed=false` and `network_allowed=false`; `execute_one_shot` always denies. There is no HTTP client, token reader, mutation transport, branch writer, nonce creation or activation switch. The simulated atomic create-if-absent nonce and non-force sandbox CAS operate on a deep copy of a Python dictionary, not GitHub.

`tests/phase_c_new_trusted_writer_test.py` covers correct fixture and digests, tampered content/paths, wrong PR head/base/draft, fake owner/approval, changed or bypassed rulesets, consumed nonce, conflicting branch, invalid atomic envelope, ambiguity/no-retry, hostile injected transport and always-disabled execution. An import in the existing `tests/phase_c_c2_trusted_executor_test.py` makes the new tests run in the already read-only `phase-c-c2-trusted-executor-tests.yml` workflow, without touching that workflow or granting additional rights.

Run offline: `python3 -m unittest discover -s tests -p phase_c_new_trusted_writer_test.py -v`.

## Hard security boundary

1. Synthetic snapshot matches DO NOT authenticate the owner, GitHub App, issue/comment, permissions, nonce absence or a writer. `REVIEW_ONLY` is never an execution permit.
2. Future activation requires **separate security-reviewed code**, independent authenticated live checks of the existing App's exact repository scope and effective rulesets, an owner-created **new** issue and fresh exact owner-authored approval comment bound to a reviewed immutable worker SHA and the actual candidate commit.
3. Only after separate specific authorization could a future trusted adapter attempt one atomic nonce-create-if-absent and sandbox compare-and-swap transaction. Unknown/timeout/conflict outcome is terminal; never automatically retry or reset a nonce.
4. Following any separately authorized attempt: independently read back branch, nonce, candidate parent, file scope, blob digest and draft/unmerged PR status. This development PR never attempts those writes.
5. No merges of fixture PR #426 or #475, workflow dispatch, releasegate changes, OVH, DB, secrets, permissions, deployment or production actions.

**Merging the separate development PR requires a fresh exact authorization. Green CI is evidence, not authorization to write.**
