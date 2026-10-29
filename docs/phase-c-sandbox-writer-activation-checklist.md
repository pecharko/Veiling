# Phase C sandbox writer: trust boundary and activation gate

This proposal does not grant or use GitHub write permissions.

## Target behavior after separate approval

A privileged controller may propose and then perform **one** deterministic change on an owner-created `test/phase-c-*` branch, and nowhere else. The trusted controller must NOT checkout or execute untrusted PR code with a write-capable token. Authorization must be proven by fetching an owner-authored GitHub issue/comment and exact immutable SHA; never use a user-supplied `author_approved` boolean as authorization evidence.

## Required enforcement before activation

1. **Identity:** verify issue author and authorization commenter against GitHub owner ID, then check issue still open and approval exact/one-time.
2. **Scope:** exact repo, exact PR number, source branch `test/phase-c-*`, and a single file matching `tests/*_test.php`. Reject PRs from forks, protected paths, and workflow changes.
3. **Freshness:** fetch actual PR head SHA immediately before write and compare with previously approved immutable head SHA. Fetch target blob SHA, compare with approved blob SHA and use contents API compare-and-swap. Stop at first drift.
4. **Patch:** deterministic, bounded replacement only; compare expected original text, replacement byte count, and expected new content hash before committing. No arbitrary shell commands or agent-generated executable scripts.
5. **Isolation:** do not run PR payloads under write token, expose no OVH or deployment secrets. A dedicated job is the sole holder of a minimal content-write credential.
6. **Idempotence:** exact approval nonce or issue ID can authorize at most one attempt; no retries, force-pushes, merges, workflow dispatch or database/production access.
7. **Verification:** verify exactly one commit/one allowed file and required tests on the new immutable SHA. An error reports its status without automatic retries.

## Current readiness

`scripts/development_ci_write_gate.py` is an **offline policy model** that cannot authorize any write. A future writer must replace its synthetic approval flag with actual GitHub owner evidence. Existing `.github/workflows/phase-c-sandbox-dryrun.yml` is read-only.

The next approval must explicitly authorize the **exact GitHub workflow/code and permissions change**. Merely saying "start the next step" is permission to prepare, not authorization to activate this write channel.
