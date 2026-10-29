# Phase C: one-shot sandbox writer transaction (not activated)

This is a design and acceptance checklist, not an authorization to write.

## Trust boundaries
- A trusted controller runs from a pinned `main` commit; untrusted PR code is never executed in the credential-bearing job.
- A scoped GitHub Actions installation token with `contents:write` is held only by the eventual writer step, not by the read-only evaluation or untrusted PHP tests. Separate approval is required to add this permission.
- `scripts/phase_c_github_evidence_reader.py` and `scripts/phase_c_owner_evidence.py` are advisory only. Their caller must validate authenticated API responses, provenance, and exact issue author/commenter identity. A fabricated input object is never authorization.

## One-shot proof and write flow
1. Fetch canonical PR, head ref, issue, issue comment, head blob and protected default branch through authenticated GitHub endpoints. Reject forks, closed PRs, workflow/permission edits and `main`.
2. Verify owner numeric ID and the exact `PHASE-C-SANDBOX-ONCE pr=... head=... blob=...` comment. Pin issue ID, comment ID, PR head SHA, target path, blob SHA and expected output SHA-256.
3. Reserve the authorization nonce in a durable, atomic **single-use** store before any write. Fail closed when already used; do not use an input `used=false` flag as real replay protection. Retries after ambiguous API failure are forbidden.
4. Retrieve exact current head and blob again immediately before write. Reject any drift. Validate deterministic replacement is precisely one `return flase;` to `return false;` in `tests/phase_c_sandbox_test.php`, with a bounded file and unchanged surroundings.
5. Ensure the write is atomic against the exact expected ref/blob, or else refuse to write. GitHub Contents API's blob SHA alone does **not** guarantee branch-head compare-and-swap. A conditional Git ref update (`force=false`) plus verified single-file tree is needed; refuse if branch moved.
6. Read back new commit, parent, tree diff and file hash. Verify exact one-file change and no workflow/permission changes.
7. Run CI on the new immutable commit; report outcome without merge, dispatch or OVH/DB operation. Manual intervention on any mismatch.

## Before proposing activation
- Implement real authenticated read-only transport, branch-head CAS and durable one-shot nonce storage under tests; test concurrency, replay, drift, fork, malformed data and partial failures.
- Verify least-privilege token restrictions and no access to production secrets. Obtain security review and passing PR and main CI.
- Present checkbox authorization for **exact workflow file, trusted commit, granted permission, target sandbox and one test attempt**. The checkbox submits the scoped approval as a new turn, not a direct write.
- Perform exactly one sandbox write only after verified activation approval. Production remains independent.
