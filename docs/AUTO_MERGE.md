# Controlled auto-merge on GitHub Free

This workflow is deliberately **opt-in and narrow** because this private GitHub Free repository does not have enforceable required branch checks.

- Maintainer creates the `auto-merge-approved` label once and adds it to a reviewed, same-repository PR.
- A successful completion of any of the three CI workflows triggers the check. All three **latest** PR runs must have succeeded on the **same current PR head SHA**.
- Only modifications to `catalogus-public/admin/admin.css`, simple `docs/*.md`, or `CHANGELOG.md` qualify. Any added/deleted/renamed file or changes to PHP, JS, workflows, deployment, database, secrets, security, backups, or authentication are excluded.
- PR must be non-draft, mergeable, based on `main`, with no merge conflict. The merge API checks the expected head SHA again.
- The workflow never deploys. OVHcloud remains behind separate manual `workflow_dispatch` and explicit approval.
- Remove the label to opt out. Never let untrusted users apply this label or edit `main` workflows.
- **Security limitation:** without branch protection, maintainers with write access can still bypass these conventions, and this workflow is not equivalent to GitHub's protected-branch auto-merge.
- PRs that change this workflow require a manual merge and code review.

To expand eligibility to PHP or other runtime code, first introduce enforceable protections and a security review. Do not simply widen the allowlist.

## Verificatietest

Een documentatie-PR met het label `auto-merge-approved` dient als gecontroleerde test: pas na drie geslaagde CI-controles op de actuele commit mag de workflow automatisch mergen. Dit voert geen productiedeployment uit.
