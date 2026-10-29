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
