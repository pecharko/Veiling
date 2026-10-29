# Phase C: single-call atomic updateRefs — security review proposal

**Status:** REVIEW ONLY. This document and the accompanying offline planner
do not replace or activate the existing two-stage design.

## Verified GitHub API contract

Official schema: https://docs.github.com/en/graphql/reference/git

`updateRefs` accepts an array of `RefUpdate` objects and specifies that
all ref updates are atomic: rejection of any update rejects the others.
`beforeOid=0000000000000000000000000000000000000000` asserts
non-existence. `beforeOid=<original sandbox head>` asserts an exact branch
head. Both updates have `force=false`.

## Exact proposed scope

- Repository: pecharko/Veiling; repository node R_kgDOVNLxvg
- Owner issue: #427; isolated draft sandbox PR: #426 (never merge)
- First update: create `refs/tags/phase-c-once-issue-427`
  conditional on the ref not existing; point it at original sandbox head
  `2595a63c6cd575dbbb7f29f293ccccaccc140553`.
- Second update: move only `refs/heads/test/phase-c-writer-fixture-20261029`
  from precisely that head to the separately constructed, verified
  single-file candidate commit. Candidate OID is **not** known or authorized
  by this review proposal.
- Both changes in ONE `updateRefs` call, only after authenticated independent
  owner, comment, PR, original blob, candidate content/diff, install,
  effective ruleset and single-use authorization verification.

## Constraints and unresolved proofs

1. `updateRefs` atomicity is documented, but **the actual GitHub App**
   currently has only Contents:read. Effective permissions and ruleset
   enforcement for its specific writer identity remain untested.
2. Rulesets `26113833` (tag update/delete) and `26113993`
   (branch deletion/non-fast-forward) were read as active with no bypass.
   This does NOT establish whether tag creation or combined mutation
   is permitted for the intended App.
3. A successfully returned GraphQL acknowledgement is not enough:
   independently read back both refs, verify exact commit parent and
   one-file diff, and preserve immutable evidence.
4. On network timeout, ambiguous response, conflict or partial readback,
   **do not automatically retry**, recreate, delete or repurpose the nonce.
5. No request should be executed from CI or untrusted PR code; installation
   credentials remain local. Never accept provided boolean flags as proof
   of identity or authorization.
6. The existing two-stage code, tests, and trust contract remain in force
   until a distinct review and explicit approval of any migration. No
   release gate, writer switch, permissions, workflow dispatch, sandbox
   ref or production change is included here.

## Benefits versus existing two-stage design

If both conditions are accepted by GitHub, the nonce reservation and branch
compare-and-swap succeed together; a rejected branch update will not spend
the nonce. This reduces partial transaction outcomes, but ambiguity in the
network response still requires independent readback and a closed retry gate.

**Next boundary:** independent security review of App/ruleset semantics and
exact candidate OID; separate narrowly scoped authorization for real writer
permissions and a single sandbox operation.
