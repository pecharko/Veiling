# Phase C durable nonce storage: implementation contract

This file is a design-only gate. It grants no GitHub permissions.

## Minimum implementation before any sandbox write

- Use one GitHub-native, trusted, durable store with a unique key derived from the owner-authorized immutable issue ID (the comment ID stays pinned to the signed-in approval evidence). The reservation operation MUST be atomic (a single compare-and-create), and **must happen before** any write to the target sandbox branch.
- Once the first conditional reservation succeeds, keep the nonce spent regardless of all subsequent outcomes. If reservation itself fails or times out, forbid branch updates and all automatic retries; absence cannot be assumed. No retries after timeout, conflicts, or ambiguous GitHub responses. Parallel jobs with the same nonce must yield one winner and all other runs denied.
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

## Reserve-first GitHub-native two-stage strategy (review-only, not active)

GitHub documents the GraphQL `updateRefs` mutation as atomic **within one mutation request**. It supports `beforeOid` with forty zeroes to require a missing ref. Two refs updated in one call succeed or fail together, which **does not** durably consume the nonce when a branch update is rejected. That contradicts the strict one-attempt rule. The earlier two-ref design is superseded; it MUST NOT be activated.

The conservative procedure is **two separate conditional mutations** in a future independently approved trusted executor, never in PR-controlled code:

1. **Reserve first**: create `refs/tags/phase-c-once-issue-<immutable issue ID>` with `beforeOid=000...000`, `afterOid=<approved original head>`, `force=false`. This conditional creation is atomic for this tag. A second issue comment cannot renew the same issue's budget. Record success evidence. Never delete or roll back this tag. A 404 readback is not proof that it is absent to a writer.
2. **Advance only after reservation success is unequivocally verified by the trusted controller**: use a **different** GraphQL `updateRefs` request with exactly one update to `refs/heads/test/phase-c-...`, `beforeOid=<original approved head>`, `afterOid=<independently verified candidate commit>`, `force=false`. Recheck owner evidence, branch and blob immediately before the operation. If the branch changed or update failed, the tag remains spent. **No retry**, even if its ref still points at the old commit.

A failed/ambiguous first reservation never authorizes the second step and must not be retried automatically. The two stages are **not an atomic pair**: a reserved-but-unchanged branch is an expected safe failure state. Observed tag existence by itself is not proof that this specific attempt created it. Reconciliation only observes state and requests manual review; it never generates a new write. Both stages remain **design-only** in `scripts/phase_c_atomic_refs_plan.py` and expose `write_allowed=false`. The future trusted executor must also enforce a durable no-delete tag ruleset, verified credential scopes and a single active owner issue per attempt. A trusted process must not give untrusted PR checkout/execution access to the write token.

The owner-verified and proposed-commit-verified flags in the current simulator are **not** an authentication mechanism. A separately approved worker must read authenticated GitHub evidence, verify exact parent/tree, one-file diff, no fork, expected digest and branch protections. The branch-protection endpoint returned 403 to the current integration on 2026-10-29; its rules remain unverified and **activation must fail closed**. No actual nonce reservation, branch update, token, permissions or production deploy are enabled.

API: https://docs.github.com/en/graphql/reference/git#updaterefs

## Authenticated read-only candidate verification (inactive)

The inert `scripts/phase_c_readonly_candidate_verifier.py` combines the existing owner/issue/comment verification with read-only GitHub REST evidence for the proposed sandbox commit. It verifies repository identity, candidate's one exact parent, complete untruncated recursive original/candidate trees, a single modified regular-file blob at `tests/phase_c_sandbox_test.php`, Git SHA-1 for the old and new base64 blob bytes, deterministic `return flase;` -> `return false;` correction, and expected SHA-256. Negative tests include impersonation, wrong parent, additional paths, changed modes, truncated trees, forged blobs and missing API responses.

**Trust boundary:** its injected `api_get` is only a testable read interface, not proof of a GitHub-authenticated token or response provenance. The output remains `REVIEW_ONLY` with `write_allowed=false` even when all mock checks succeed. The trustable executor must independently authenticate REST/GraphQL requests, enforce permission scopes, check branch and tag rules, verify exact owner authorization and implement durable one-shot atomic mutation/reconciliation. Never derive write authority from caller-provided flags or from this module alone. GraphQL and Git Database APIs can create objects or refs; this module only reads existing data.

The exact live credentials, proposed commit creation, mutation dispatch, ambiguous-success reconciliation, audit evidence and failure recovery have **not** been enabled, tested against production GitHub branches or authorized. No OVH deployment is involved.


## Scoped GET-only client and ambiguous CAS response reconciliation — inactive

The proposed `scripts/phase_c_github_readonly_client.py` has an explicit allowlist for GitHub REST GET requests needed for Phase C, a fixed `https://api.github.com` origin, redirect rejection, bounded response size and timeout, and a caller-injected bearer credential. **It never reads an environment secret, requests a credential, enables GitHub Actions permissions, changes any ref or performs a non-GET HTTP request.** Existing CI runs it only with a fake opener; no workflow uses the adapter for live GitHub API requests.

`scripts/phase_c_atomic_ref_reconciliation.py` defines the safe response to an unknown `updateRefs` result. It performs two bounded read-only observations of the exact nonce tag and approved sandbox branch and refuses every write, retry, merge and dispatch. Nonce at the original head with branch at the candidate yields only `SPENT_NONCE_CANDIDATE_OBSERVED`; nonce at the original head with unchanged branch yields `SPENT_NONCE_BRANCH_UNCHANGED`; nonce 404 with unchanged branch yields `NONCE_NOT_OBSERVED_NO_RETRY`. Partial/moving/unexpected states and API errors require manual review. Two consecutive GETs are **not** an atomic GitHub snapshot and do not prove that the original mutation succeeded. A 404 can also reflect lack of permission or concealment: it is NOT proof of an available nonce. Never delete the tag or automatically retry, even if both GET rounds appear unchanged.

Activation remains blocked until a separately approved trusted executor can authenticate the owner issue/comment and transport identity, verify the candidate commit/tree/blob and head at decision time, confirm GitHub branch/tag protection and exact token privileges, validate the GraphQL `updateRefs` contract, and reconcile true one-shot behavior on GitHub. In particular, this new GET client does not make `owner_verified` flags, simulated nonce states or the offline planner into write authorization. No production actions are allowed.


## Integrated complete read-only sandbox audit (inactive)

The `scripts/phase_c_complete_readonly_audit.py` entrypoint is a non-authorizing review that joins the existing owner/issue/comment and candidate-commit verifier with **two bounded GET-only readbacks** of the exact pinned `main` ref, owner-approved sandbox branch and issue-scoped nonce tag. `scripts/phase_c_github_readonly_client.py` now allows an explicit GET of `refs/heads/main`; it still denies reading or mutating arbitrary branches. An additional pinned `main` SHA must be provided for the review, independently of the approved sandbox head.

The report denies a stale `main`, changed sandbox head, existing nonce tag, inconsistent consecutive reads, missing or malformed GitHub responses, incorrect candidate/owner evidence, and all API errors. Two apparent nonce 404 responses produce **`READ_ONLY_REVIEW_REQUIRED`**, *not* `READY`: REST 404 can conceal permissions or rule restrictions and cannot establish whether the future trusted writer may conditionally create the tag. Every report enforces `write_allowed=false`, `retry_allowed=false`, `merge_allowed=false`, `dispatch_allowed=false`, `production_authorized=false` and `ready_for_activation=false`. Reports contain no API token or executable mutation payload.

Only fake injected API responses are used by CI. The audit does not reserve a nonce, create a candidate commit, change Git refs, inspect actual protected branch/tag permissions or activate a workflow. A separately authorized trusted writer with independently authenticated API transport, verified production-safe privileges, exact owner proof, durable first-stage tag reservation and conditional second-stage branch update is still needed. Because `main` and the sandbox can change between the last GET and a future write, the trusted writer must revalidate its own snapshot and rely on the actual GitHub CAS precondition; no audit verdict grants permission.
