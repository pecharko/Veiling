# Phase C trusted sandbox writer: activation gates (design only)

Status: **NOT IMPLEMENTED / NO WRITE AUTHORIZATION**. This file is documentation, not an activation switch. Scope is only draft PR #426, owner issue #427, and `tests/phase_c_sandbox_test.php`. Never merge the fixture PR.

## Immutable candidate

- Repository: `pecharko/Veiling`.
- Sandbox branch: `test/phase-c-writer-fixture-20261029`.
- Original head: `2595a63c6cd575dbbb7f29f293ccccaccc140553`.
- Original blob: `a16bb38acbde1422389e1fcb4835c20688f594b3`.
- Sole proposed edit: `return flase;` -> `return false;`, exactly once in the single sandbox file.
- Expected new content SHA-256: `013a57991e6285797587766f1bfb422d5f27045a1ca91531dd772b44958e9323`.
- Expected new Git blob SHA-1: `9bb4e9774e6244c143975c00bcac4826d4895317`.
- Owner-created issue #427 and owner-authored comment must be retrieved through independently authenticated GitHub API reads. The issue body/comment are evidence, not execution authority by themselves.

## Mandatory fail-closed gates before any future activation

1. **Trusted identity and token:** independently establish owner login, repository identity, token provenance, minimum necessary GitHub App permissions and writer identity. Injected strings or read-only fetch results do not prove identity or write access.
2. **Immutable state:** re-read PR #426 (draft, open, unmerged), its base and head; verify the exact file diff and original blob; ensure `main` and other PR changes do not create a path or authorization conflict. Reject a changed branch head, blob, scope or owner comment rather than silently refreshing approval.
3. **Ruleset verification:** independently read repository ruleset IDs `26113833` (tag `refs/tags/phase-c-once-issue-*`: update and deletion denied, no bypass) and `26113993` (isolated branch: deletion and non-fast-forward denied, no bypass). The effective rules for the **actual writer identity** must be confirmed; API read success alone is insufficient. Reject absent, bypassed, disabled or altered protection.
4. **Durable one-shot reservation:** a trusted writer must atomically CREATE `refs/tags/phase-c-once-issue-427` only when absent, bound to immutable issue, approval comment, head and blob. Reading a 404 is **not** proof of absence or permission. Any existing tag, ambiguous outcome, timeout, 409/422, transport error, or uncertain reservation is terminal: do not retry this issue. No deleting/replacing the tag to reclaim a slot.
5. **Atomic branch update:** only after a confirmed reservation, construct the exact deterministic replacement and commit with original head as parent. Update solely the sandbox branch using a non-force compare-and-swap operation; reject any concurrent change. Do not auto-rebase or refresh the approval. Avoid any code path that can write to `main` or to production.
6. **Post-write evidence:** independently read branch and file; verify new commit parent, exact path, corrected content digest, expected blob, absence of unexpected changed files, preserved nonce tag and draft/unmerged PR. Capture immutable machine-readable evidence. An ambiguous write response must not cause a retry.
7. **No authority escalation:** no PR merge, workflow dispatch, issue mutation, OVH/SFTP/DB changes, secrets, permissions, releasegate edits or deployment. Separate scoped human approval and security review required for **any** first sandbox mutation.
8. **Fail-safe behavior:** the default implementation must expose read-only/dry-run only. Activation should require an explicit disabled-by-default path and authorization tied to current immutable evidence; CI must never activate the writer.

## Required offline/security tests

- Happy-path **simulation** with fake GitHub transport: one tag creation, then one non-force sandbox compare-and-swap, then readbacks; no real API mutation.
- Owner, comment, PR, branch, main, blob, file or ruleset mismatch -> zero writes.
- Duplicate nonce/tag or uncertain tag-create outcome -> zero branch writes; never retry.
- Competing branch head / failed compare-and-swap -> no retry, no force.
- Exact edit only; unexpected content or additional file -> denied.
- All failure injection points produce an auditable terminal status; no production APIs are invoked.
- CI runs without write secrets and records tests separately from any subsequent human authorization.

## Current evidence (2026-10-29)

The two repository rulesets currently report `active` with no bypass actors, and the sandbox branch is protected. PR #426 remains open/draft at its original head; issue #427 has an owner-authored comment. A read of the nonce ref returned 404, **not** proven reservability. `scripts/phase_c_sandbox_writer_prepare.py` and `scripts/phase_c_sandbox_transaction.py` are proposal-only, and `scripts/phase_c_complete_readonly_audit.py` explicitly denies activation. This document does not change that state.
