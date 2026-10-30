# C2 write activation boundary — owner decision contract

> **CLOSED SANDBOX EXERCISE (2026-10-30):** The one-use nonce for issue #427 is now
> spent, the isolated branch is at candidate `11ead85e1a3d0086166da665a1a6d4bdf25477d7`,
> and the sandbox PR CI succeeded. **Do not rerun the worker, recreate the
> nonce, or merge PR #426.** See
> [the independent closeout record](phase-c-c2-sandbox-closeout-20261030.md).
> The pre-activation details below are retained for historical review only,
> not as instructions to repeat the sandbox transaction.

This PR adds only an offline shape validator for a future exact owner
authorization record. **Matching fields are NOT owner authentication.** The
report is always non-executable REVIEW_ONLY (or DENY). It must not be
connected to a live GitHub transport.

Current immutable candidate scope: pecharko/Veiling, PR #426, sandbox
head 2595a63c6cd575dbbb7f29f293ccccaccc140553,
single file tests/phase_c_sandbox_test.php, nonce ref
refs/tags/phase-c-once-issue-427, issue #427, installation 176547610.

No candidate commit object currently exists as authenticated evidence.
To proceed to a real transaction, independently establish actual GitHub
App identity and granted repository rights, create and authenticate the
candidate commit/tree with exactly one patch, verify nonce and sandbox
baselines, collect explicit exact one-shot owner approval, and perform
an independently security-reviewed single atomic call followed by readback.
Do not auto-retry ambiguous outcomes. Do not upgrade GitHub App permissions
or trigger any mutation from a review verdict. Keep production out of scope.

GitHub documents updateRefs as atomic for multiple refs; zeros in beforeOid
can require absence. This API property alone is not evidence that a specific
installation can bypass or satisfy configured rulesets.
