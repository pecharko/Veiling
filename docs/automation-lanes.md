# Three distinct automation lanes

## Lane 1: development (ChatGPT active turn)
- An authorized feature request covers ordinary code edits, tests, PR preparation, bounded polling, error correction, review, and safe merge when existing policy permits it.
- Keep executing the next safe step within the same active turn. Do not end after one micro-commit or while relevant CI is queued. Limit polling and use other safe work while waiting.
- A GitHub Actions job cannot autonomously resume an ended ChatGPT turn. No autonomous coding agent is implied by this lane.
- Maintain a task checklist; final status lists completed work and remaining genuine boundary only.

## Lane 2: asynchronous CI feedback (GitHub Actions)
- `.github/workflows/development-ci-feedback.yml` runs on completion of existing PR CI workflows and writes job-level failed status to its own GitHub Actions summary.
- No writing to a feature branch, no automatic fixes, no PR merge, no retry, no commenting as repository owner.
- On subsequent active assistant work, read latest head SHA and exact CI jobs/logs; fix failed assertions or code and re-run naturally by pushing.
- Do not mistake the passive summary for test execution or code repair.

## Lane 3: production authorization (existing dedicated controller)
- `.github/workflows/verified-deploy-controller.yml` + `scripts/verified_deploy_controller.py` remain the sole existing owner-comment dispatch path.
- Exact owner issue/label/comment and immutable release manifest gate still required.
- Database/schema, snapshot/restore, security, protected cron, releases and OVH mutations require their existing scoped authorization.
- Never use CI feedback as a grant of release authorization.

## Example: PR #396
One development session should inspect PR head, run/check geographic PHP tests, diagnose failure from jobs, fix all known test cases together, review DB/archive/restore coupling and stop only if schema/production approval is truly needed. The asynchronous feedback workflow can publish that a test job failed but cannot repair it without a new active assistant session or an explicitly designed separate coding-agent service.

## Acceptance evidence
- Green *latest SHA* PR checks, not green old commits.
- Actual test step success (not only overall workflow status).
- For merge: required policy checks, correct scope, explicit approval for protected classes.
- For production: independent release and browser acceptance continue unchanged.
