# Phase C2: live sandbox acceptance boundary (inactive)

This document is a checklist, not an authorization, execution plan, or writer activation.
Nothing here permits network POST, GitHub ref mutation, token injection, workflow dispatch,
sandbox PR merge, releasegate changes, or production access.

## Currently observed read-only facts (recheck immediately before any approval)

- Repository: `pecharko/Veiling`.
- Sandbox draft PR: #426, open and unmerged when inspected.
- Original head: `2595a63c6cd575dbbb7f29f293ccccaccc140553`.
- Proposed candidate: `11ead85e1a3d0086166da665a1a6d4bdf25477d7`.
- Commit comparison: candidate is one commit ahead; only
  `tests/phase_c_sandbox_test.php` changes.
- Target branch ref: `refs/heads/test/phase-c-writer-fixture-20261029`.
- One-time nonce ref: `refs/tags/phase-c-once-issue-427`.
- Owner coordination issue: #427.
- These observations do **not** prove present ref state, a missing nonce,
  authenticated owner consent, effective App permissions, or any successful mutation.

## Independent evidence needed before offering one-shot authorization

1. Authenticate the exact owner and owner-created approval comment from GitHub
   via trusted identity-bearing responses; bind issue/comment ID, repository,
   allowed operation, exact refs, expected old/new OIDs, one allowed path,
   and expiry/use-once condition. Never accept self-asserted booleans as proof.
2. Independently validate GitHub App identity, installation repository scope,
   effective permissions and branch/tag rulesets without issuing or exposing
   credentials to CI or untrusted code.
3. Independently re-fetch the exact sandbox branch head and nonce absence
   immediately before any attempted write; fail closed on ambiguity or drift.
4. Verify the candidate commit, parent, tree and changed blob from immutable
   evidence. It must change only the isolated sandbox test file.
5. Independently security-review the currently **nonexistent** live adapter
   and verify single-call atomic ref update semantics, nonce uniqueness,
   no force, no fallback or second write, and zero automatic retries.
6. Require fresh, exact, separately scoped owner authorization for one sandbox
   transaction after all above evidence is recorded. Offline REVIEW_ONLY,
   CI green, merge, and generic 'go' are never permission.
7. After any explicitly authorized attempt, independently fetch both refs
   using authenticated GET and validate response status/identity first.
   Readback matching does not prove atomicity, authorization or causation.
   If the outcome is ambiguous, stop and investigate read-only: no retry.

## Hard stop criteria

- Any missing or contradictory identity, status, scope, ruleset, ref, nonce,
  OID, tree, auth or readback evidence.
- Any unexpected CI/network/credential path, writable runner, live adapter
  without separate review, or attempt to merge sandbox PR #426.
- Any request to infer live authorization from this document or a test result.

This checklist is deliberately non-operational. Do not enable the writer
as part of this PR.
