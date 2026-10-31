# Phase C2 isolated sandbox — observed closeout (2026-10-30)

**Status: intended sandbox end state independently observed and PR CI successful.**
This is a read-only audit record, **not** a new execution authorization,
production acceptance, reusable writer permit, or proof of the identity of
the actor that caused the observed changes. The local worker's own stdout and
exit code were not retained in this record. Do not infer HTTP request count,
atomicity or exact credential use solely from matching ref values.

## Authorized, immutable scope

- Repository: `pecharko/Veiling`; reviewed worker `main` commit
  `dbec3232c6ba6b235f7389bb32396ec44bb03efd` (PR #463 merged).
- GitHub App: `41lokeren-phase-c-writer`, App ID `5526516`,
  separate installation `176547610`; local default-mode
  `READ_ONLY_VERIFIED` was reported by the owner before write approval.
- Owner-authored issue #427 approval comment
  [#6517243750](https://github.com/pecharko/Veiling/issues/427#issuecomment-6517243750),
  author `pecharko` (numeric ID `343879284`), binds the exact worker
  commit, refs and old/new heads.
- Sandbox draft [PR #426](https://github.com/pecharko/Veiling/pull/426);
  protected branch `refs/heads/test/phase-c-writer-fixture-20261029`.
- Original head `2595a63c6cd575dbbb7f29f293ccccaccc140553`;
  candidate `11ead85e1a3d0086166da665a1a6d4bdf25477d7`,
  which has the original head as its sole parent.
- Single changed path `tests/phase_c_sandbox_test.php`:
  `return flase;` -> `return false;`.
  New Git blob `9bb4e9774e6244c143975c00bcac4826d4895317`;
  expected content SHA-256
  `013a57991e6285797587766f1bfb422d5f27045a1ca91531dd772b44958e9323`.
- One-use nonce `refs/tags/phase-c-once-issue-427`, permanently bound
  to the original head.

## Observed independent post-attempt evidence

The owner supplied REST CLI readbacks; the connected GitHub API subsequently
independently confirmed:

| Check | Verified value |
| --- | --- |
| Sandbox branch | `11ead85e1a3d0086166da665a1a6d4bdf25477d7` |
| Nonce tag (direct commit ref) | `2595a63c6cd575dbbb7f29f293ccccaccc140553` |
| PR #426 | `OPEN`, draft, unmerged; head equals candidate |
| `main` | `dbec3232c6ba6b235f7389bb32396ec44bb03efd` |
| GitHub compare | Exactly one commit ahead, one modified test file |
| Corrected file blob | `9bb4e9774e6244c143975c00bcac4826d4895317` |
| Branch ruleset `26113993` | Active; deletion/non-fast-forward disallowed; no bypass |
| Nonce ruleset `26113833` | Active; tag update/deletion disallowed; no bypass |

The sandbox PR-head CI was confirmed on the exact candidate commit:

| Workflow | Run | Jobs |
| --- | --- | --- |
| PHP syntax/repository checks | [41020446216](https://github.com/pecharko/Veiling/actions/runs/41020446216) | `php-lint` successful |
| Isolated auction-editor integration | [41020446159](https://github.com/pecharko/Veiling/actions/runs/41020446159) | `impact`, `kavel-mysql-http` successful |
| Isolated snapshot/restore | [41020446151](https://github.com/pecharko/Veiling/actions/runs/41020446151) | `impact`, `snapshot-isolated` successful |

All 3 workflows and all 5 applicable jobs completed successfully.

## Security disposition / hard stop

1. **Nonce is spent. Never rerun** `phase_c_c2_local_one_shot_worker.py --execute`
   for issue #427, including after any timeout or unexpected response.
   Do not delete/recreate the tag or reset/reuse the sandbox branch to retry.
2. **Never merge PR #426.** The fixture is intentionally isolated test code
   and not part of the production application or release pipeline.
3. This evidence is sufficient to accept the *observed sandbox end state*;
   it does not independently prove whether one specific GraphQL request caused
   both ref updates, which credential performed it, or the worker's exit code.
   Those claims require independently preserved, authenticated execution traces.
4. No production, OVH, DB, secrets, releasegate or permission change is
   authorized by this record. The GitHub App credentials must remain outside
   Actions and repository files.
5. Any future real writer or automation requires a distinct design and owner
   authorization with a new non-reusable scope. No general-purpose deployment
   writer is activated or permitted by this isolated sandbox exercise.

Historic pre-activation checklists in this repository are design records only.
Refer to this closeout before following any earlier instructions mentioning
a missing nonce, uncreated candidate commit or future execution of PR #426.
