# Phase C durable nonce storage: implementation contract

This file is a design-only gate. It grants no GitHub permissions.

## Minimum implementation before any sandbox write

- Use one GitHub-native, trusted, durable store with a unique key derived from the owner-authorized issue ID and exact comment ID. The reservation operation MUST be atomic (a single compare-and-create), and **must happen before** any write to the target sandbox branch.
- Mark the nonce spent at the start of the attempt, regardless of outcome. No retries after timeout, conflicts, or ambiguous GitHub responses. Parallel jobs with the same nonce must yield one winner and all other runs denied.
- The store must not be written by untrusted pull-request code. Its token must not be available while checking out or executing PR-controlled content.
- Inspect exact GitHub API evidence again inside the trusted writer: owner ID, PR branch and fork status, issue/comment IDs, approved head SHA, blob SHA and expected result digest.
- Reject if `main`, the target branch head, target blob, owner approval, permission scope, or target path changed.
- A GitHub Contents API `sha` parameter is **not** enough to prevent a concurrent branch-head update. Implement an atomic branch-head reference compare-and-swap with a single-file tree and expected old commit, or abort.
- After a successful write, fetch GitHub's new commit and verify expected parent, exactly one modified file, target content digest and no permissions/workflow changes. Only then observe CI on that immutable commit.

## Verification tests required

1. Same nonce submitted in parallel: only one attempt permitted.
2. First attempt fails or times out: nonce remains consumed; no automatic retry.
3. Branch head changes between read and write: no update.
4. Blob changes without an approved digest: no update.
5. Fork, invalid GitHub owner, wrong path or comment body: no update.
6. Successful one-file sandbox correction: exact parent and diff verified; no merge or production action.

Current `phase_c_one_shot_preflight.py` accepts simulated reservation state and always returns `can_write=false`. It is not a durable reservation and cannot be used as proof for activation.

## Preferred GitHub-native atomic strategy (review-only, not active)

GitHub GraphQL documents `updateRefs` as an **atomic** mutation of multiple Git refs. The next implementation should use exactly two conditional updates in the *same* mutation:

1. Create `refs/tags/phase-c-once-issue-<immutable issue ID>` with `beforeOid` equal to forty zeroes and `afterOid` equal to the independently verified candidate commit. This is the durable one-shot marker (never delete it on success, conflict or failure).
2. Update only `refs/heads/test/phase-c-...` with `beforeOid` equal to the owner-approved original head and `afterOid` equal to the same candidate commit. Both updates must set `force=false`.

If the nonce exists **or** the branch head has changed, the entire mutation is rejected; no partial branch write or new nonce marker should be committed. This is preferable to an uncoordinated two-request GitHub REST reservation plus branch update. A second owner comment on the same issue does not create a new nonce.

The preparation in `scripts/phase_c_atomic_refs_plan.py` only constructs an inert GraphQL variable preview; it cannot submit a mutation. The values `owner_verified` and `proposed_commit_verified` remain advisory **unless a future trusted process independently verifies signed-in API provenance, proposed commit parent/tree, one-file diff and content SHA-256**. There is currently no authenticated live writer, no write permission, no actual nonce ref and no deployed controller.

**Before activation:** confirm whether repository rule sets permit the two conditional ref updates on the sandbox and tag namespace. If GitHub refuses or its semantics differ, fail closed; do not fall back to separate non-atomic writes. Require a separate scoped approval for the workflow, token privileges, immutable source commit and a single sandbox attempt. An ambiguous response must trigger read-only reconciliation and manual review, never blind retry.

API reference: https://docs.github.com/en/graphql/reference/git#updaterefs

Read-only repository metadata on 2026-10-29 confirmed the GitHub GraphQL repository node ID `R_kgDOVNLxvg`, now pinned by the offline planner. The active GitHub integration could list repository rulesets but received HTTP 403 from the branch-protection endpoint. **This is not proof that protections are absent.** A separately authorized trusted activation must verify all relevant branch and tag rules, and fail closed on missing read access.

## Authenticated read-only candidate verification (inactive)

The inert `scripts/phase_c_readonly_candidate_verifier.py` combines the existing owner/issue/comment verification with read-only GitHub REST evidence for the proposed sandbox commit. It verifies repository identity, candidate's one exact parent, complete untruncated recursive original/candidate trees, a single modified regular-file blob at `tests/phase_c_sandbox_test.php`, Git SHA-1 for the old and new base64 blob bytes, deterministic `return flase;` -> `return false;` correction, and expected SHA-256. Negative tests include impersonation, wrong parent, additional paths, changed modes, truncated trees, forged blobs and missing API responses.

**Trust boundary:** its injected `api_get` is only a testable read interface, not proof of a GitHub-authenticated token or response provenance. The output remains `REVIEW_ONLY` with `write_allowed=false` even when all mock checks succeed. The trustable executor must independently authenticate REST/GraphQL requests, enforce permission scopes, check branch and tag rules, verify exact owner authorization and implement durable one-shot atomic mutation/reconciliation. Never derive write authority from caller-provided flags or from this module alone. GraphQL and Git Database APIs can create objects or refs; this module only reads existing data.

The exact live credentials, proposed commit creation, mutation dispatch, ambiguous-success reconciliation, audit evidence and failure recovery have **not** been enabled, tested against production GitHub branches or authorized. No OVH deployment is involved.


## Scoped GET-only client and ambiguous CAS response reconciliation — inactive

The proposed \`scripts/phase_c_github_readonly_client.py\` has an explicit allowlist for GitHub REST GET requests needed for Phase C, a fixed \`https://api.github.com\` origin, redirect rejection, bounded response size and timeout, and a caller-injected bearer credential. **It never reads an environment secret, requests a credential, enables GitHub Actions permissions, changes any ref or performs a non-GET HTTP request.** No workflow invokes this code.

\`scripts/phase_c_atomic_ref_reconciliation.py\` defines the safe response to an unknown \`updateRefs\` result. It performs two bounded read-only observations of the exact nonce tag and approved sandbox branch and refuses every write, retry, merge and dispatch. Matching nonce and branch at the candidate commit gives only \`MATCHING_REFS_OBSERVED\`; nonce 404 with unchanged branch gives \`UNCHANGED_REPORTED_NO_RETRY\`; partial/moving/unexpected states and API errors require manual review. Two consecutive GETs are **not** an atomic GitHub snapshot and do not prove that the original mutation succeeded. A 404 can also reflect lack of permission or concealment: it is NOT proof of an available nonce. Never delete the tag or automatically retry, even if both GET rounds appear unchanged.

Activation remains blocked until a separately approved trusted executor can authenticate the owner issue/comment and transport identity, verify the candidate commit/tree/blob and head at decision time, confirm GitHub branch/tag protection and exact token privileges, validate the GraphQL \`updateRefs\` contract, and reconcile true one-shot behavior on GitHub. In particular, this new GET client does not make \`owner_verified\` flags, simulated nonce states or the offline planner into write authorization. No production actions are allowed.
