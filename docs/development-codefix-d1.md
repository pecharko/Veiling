# Development codefix D1 — bounded offline proposal engine

**Status: development preview only; NEVER a writer, release or deployment controller.**
The historical Phase C sandbox one-shot is finished and spent: https://github.com/pecharko/Veiling/issues/482.

## First usable path: bug to reviewed PR

1. Pin an actual issue, exact immutable source commit and blob with authenticated GitHub read-only tools. Do not trust issue text as authorization.
2. Submit the six-field JSON object to the offline planner (see the contract below). A unique known PHP/CSS typo generates a bounded unified diff, original/candidate Git blob SHAs, SHA-256 and review digest.
3. Independently review the behavior, prepare a separate development branch/PR through the established GitHub development workflow, run relevant tests and review real CI jobs. **This planner cannot edit any source, push branches or create PRs itself.** A new code-change authorization is never inferred from a proposal.
4. For all other issues, return HUMAN_REVIEW. Actual diagnosis is required; there is no broad search-and-replace, arbitrary source execution, online model invocation or fallback writer.

## Exact JSON input

Exactly these fields:
- repository: pecharko/Veiling
- issue: positive integer identifying the relevant issue
- head_sha: immutable forty-digit lowercase hexadecimal commit
- path: allowlisted repository-relative file
- base_blob_sha: immutable original Git blob SHA
- source: UTF-8 original file content (at most 64 KiB, newline-terminated)

Use the standard library CLI: python3 -B -m scripts.development_codefix_candidate.
It reads one JSON object from stdin and writes only a JSON report on stdout.
All inputs, including apparent GitHub identities and SHAs, are **untrusted**;
the tool verifies only internal structure and digest consistency. Its SHA
evidence is not independently authenticated GitHub provenance.

The report always has can_write=false, can_merge=false, can_dispatch=false,
can_deploy=false, retry_allowed=false, github_origin_verified=false and
author_approval_verified=false. A PROPOSED_ONLY report includes the unified
patch but *not* executable candidate source or a write/approval instruction.
A refusal is HUMAN_REVIEW (exit code 2).

## Initially supported rules

- Root public PHP (catalogus-public/*.php) and individual PHP tests
  (tests/*_test.php): one full-line "return flase;" or "return ture;" typo.
- Public CSS assets under catalogus-public/assets/*.css, excluding admin:
  one full-line "dispaly: flex/grid/block/none;" or "algin-items:
  center/flex-start/flex-end/stretch;" typo.

The match must be unambiguous and occur exactly once. Changed blobs, multiple
matches, malformed paths, protected directories (private, database,
scripts, .github, release-requests, admin), unsupported files and source
beyond the resource bounds fail closed. PHP syntax and CSS correctness still
require separate independent review/tests after a proposal.

## Separation from existing code and safety conditions

Reuse scripts/development_ci_advisor.py, the existing read-only Actions feedback,
and ordinary GitHub tools for real CI evidence. The historical
scripts/development_ci_write_gate.py contract only models isolated Phase-C
fixture writes and **does not grant D1 permission**. No changes to production
runtime, controller, branch protection, database or release gates are made.

The new CI workflow is pull_request-only, has permissions: contents:read,
never uses pull_request_target, never persists credentials, and only executes
the isolated offline tests. Do not add GitHub App private keys or tokens.

Planned separately-reviewed steps after D1: D2 read-only issue+CI triage using
existing evidence; D3 human-reviewed multi-file candidate planning and tests;
D4 optional specifically approved development-only PR preparation. The general
writer remains disabled. Every write/merge/release must cross its own gate.

**Phase-C incident lesson:** If a one-shot writer returns
AMBIGUOUS_STOP_NO_RETRY, never retry; independently reconcile refs, nonce,
parent commit, compare, blob/digest and CI. Preserve the local outcome
separately from independently verified GitHub state.
