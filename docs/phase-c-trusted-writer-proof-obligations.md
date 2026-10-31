# Phase C trusted writer: remaining proof obligations

**Status: design review only; activation prohibited.** This checklist extends `docs/phase-c-trusted-writer-activation-gates.md` without modifying the sandbox fixture, any controller, GitHub permissions or workflow dispatch.

## Distinguish the three identities

1. **Human approver**: retrieve issue #427 and the original comment via independent authenticated GitHub reads; verify the author is the repository owner `pecharko`. A typed username or an injected fixture is not authentication.
2. **CI worker**: the workflow that executes offline unit tests currently has `contents: read`. Never reuse its identity as a mutating agent or give the offline test workflow extra permissions.
3. **Potential writer**: separately determine a short-lived token's issuance, repository scope, actual permission claims, expiration, ruleset bypass behavior and auditable principal. No token or GitHub App installation is requested by this document.

## Mutation protocol that remains unimplemented

- Re-read the exact PR #426 head `2595a63c6cd575dbbb7f29f293ccccaccc140553`, fixture blob `a16bb38acbde1422389e1fcb4835c20688f594b3`, issue/comment and both active rulesets. Fail closed for any drift.
- Bind the issue number, owner comment, expected branch head, original blob and exact target file to a single authorization record; reject any unbound or reused approval.
- The nonce must be a **create-if-absent** tag ref `refs/tags/phase-c-once-issue-427`; the proposed future writer must not treat read-only 404 as proof that creation is safe.
- Record a terminal result for any failed/ambiguous nonce creation. **Never retry the same issue**, including after timeout, 409/422 or ambiguous API response. Existing tag means consumed.
- Only upon confirmed nonce creation may a future, separately approved writer attempt a single non-force branch compare-and-swap from the approved head. On conflict, stop without retry or force.
- Independent readback must establish the tag remains, the branch parent is the approved head, only `tests/phase_c_sandbox_test.php` changed, the single typo became `return false;`, the expected content/blob hashes match and PR #426 is still draft and unmerged.
- Never merge PR #426, change `main`, dispatch Actions, deploy, alter OVH/DB, secrets, permissions or release gates.

## Simulation vs. evidence

- `scripts/phase_c_offline_writer_simulation.py` and its tests demonstrate *logical ordering and refusal paths only*. They do **not** establish actual API atomicity, token identity, GitHub ruleset effectiveness for a writer, concurrency protection or durable one-shot enforcement.
- The isolated read-only CI job verifies only that the simulation compiles and its offline tests pass.
- A real writer needs its own reviewed architecture, least-privilege identity, independent effective-permission evidence, recorded operator authorization and fail-closed tests before any live attempt.

## Required manual security-review outcomes

| Gate | Evidence required | Current status |
| --- | --- | --- |
| Current owner approval | Owner-authored GitHub issue and comment; immutable values match | Observed read-only, not executable authority |
| Tag/branch rules | Active scoped rulesets, no bypass, effective writer rules | Configuration observed; writer effectiveness unknown |
| Credential provenance | Independently verified token, principal, limited scope and expiry | Not available |
| One-shot safety | Atomic real create-if-absent and terminal ambiguity handling | Only simulated |
| Branch CAS | Single non-force writer implementation and independently verified readback | Only simulated |
| Human activation | Separate exact authorization for first sandbox mutation | Not granted |

**Decision:** remain read-only / offline. Do not enable an execution pathway based on this checklist or green CI alone.
