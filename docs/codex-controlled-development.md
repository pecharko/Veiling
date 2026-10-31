# Codex as the development engine (proposed)

Status: repository guidance under draft review, **no Codex task has been
launched** and no GitHub integration/credentials have been installed. This
document does not add an autonomous writer or grant new authorizations.

## Responsibility split

**Codex** analyzes a task or bug, reproduces it where feasible, edits code on
a dedicated development worktree, adds tests and prepares a reviewable diff.
It can suggest a branch and PR; any commit, push or PR creation must remain
within the owner-requested development scope and available tool permissions.

**veiling-deploy-manager / the repository owner** decides how to verify
the exact immutable GitHub head, review sensitive changes, authorize merges,
validate main push-CI and prepare separately approved production releases.
The one-shot Phase-C workers, nonce tags and authenticated issue comments
are NOT an authorization path for the general codefix engine.

## Recommended initial workflow

1. Select a real issue and declare the desired behavior and acceptance tests.
   Check open PRs for overlapping files and start from a current, clean main
   worktree in an isolated development branch. Codex must read AGENTS.md.
2. Ask Codex to investigate and implement the minimal candidate, including
   negative/security regression tests. Avoid sending secrets or config dumps.
3. Run relevant local tests. For PHP changes: use PHP 8.2 syntax checks and
   existing focused tests. For Python: run the matching offline unittest file.
   For multi-file changes: identify relevant GitHub PR workflows.
4. Inspect the diff and changed path list. Separate sensitive or unrelated
   changes into distinct branches/PRs rather than broadening one approval.
5. Prepare a draft PR only when requested; capture exact head SHA and changed
   files. Let normal pull_request CI run, inspect jobs, fix safe failures.
   Never treat a green generic CI status as evidence of test coverage unless
   the expected test jobs actually ran.
6. The owner/veiling-deploy-manager authorizes only the next required
   operation. No automatic merge, release, database, cron, OVH or deployment.

## Example prompt to give Codex

Investigate GitHub issue NUMBER in pecharko/Veiling. First check the current
main, open PRs and relevant files for overlapping changes. Work only on a
new isolated development branch. Reproduce the bug and propose the smallest
fix plus regression tests. Do not access secrets or production. Run targeted
checks and show exact results. Prepare a draft PR only within this development
scope. Do NOT merge, dispatch workflows, modify release/security gates or
deploy. Stop with a concise handoff at the next owner approval boundary.

Replace NUMBER with an actual GitHub issue; do not guess missing requirements.

## How to start on the owner's Mac

The repository is at /Users/chrisdemol/Code/GitHub/Veiling.

- Install the supported Codex app or CLI separately if not already present,
  and sign in through the official Codex interface. Never paste GitHub App
  private keys or GitHub production credentials into Codex prompts.
- Use the repository directory in Codex and review its access/approval
  settings. Prefer filesystem sandboxing and ask before network or GitHub
  remote mutations. Preserve existing local changes.
- The repository root AGENTS.md becomes relevant only after this documentation
  PR has been approved and merged into main (or the branch has been checked
  out). Documentation cannot by itself enforce a permission restriction.
- Start with one small, non-production bug to validate the workflow. Inspect
  Codex's diff and all tests before requesting the independent GitHub review.

## Existing workstream and acceptance criteria

- PR #483 tracks a deterministic, strictly OFFLINE PHP/CSS suggestion
  engine. Check its current status independently. It is separate from Codex
  and MUST NOT be silently merged or rewritten to connect Codex. Its
  security and test review stays independent.
- The completed Phase-C sandbox proof is recorded in closed issue #482.
  The local ambiguous worker receipt and independently proven remote state
  must remain separately documented. No replay.
- This Codex onboarding PR changes **only AGENTS.md and this guide**.
  No GitHub Actions workflow, App permission, secret, release controller or
  production runtime is modified.
- Acceptance: minimal doc-only diff, existing PR CI on its exact head,
  draft status maintained until owner authorizes an exact merge. Any
  Codex application setup is a distinct explicit user action.
