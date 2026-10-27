# B3.3 — OVH shared-hosting claim boundary (design only)

Status: **NOT IMPLEMENTED / NO PRODUCTION AUTHORIZATION**. Baseline main `da78fee38f17044175a00fda752ad27894deb308`. Owner reports creation and verification of `controller_release_claims` on OVH MySQL `kobejoechris`; no independent live DB query access.

## Existing functionality — reuse, do not replace

- `scripts/controller_one_shot_claim_contract.py`: canonical approval identity and fail-closed state assessment.
- `scripts/controller_transactional_claim_model.py` and `scripts/controller_sqlite_claim_experiment.py`: uniqueness/concurrency evidence in isolated tests.
- `database/controller_release_claims_migration.sql`, `tests/controller_mysql_claim_integration.php`: schema plus real isolated MySQL 8.4 proof.
- `scripts/verified_deploy_controller.py` + `.github/workflows/verified-deploy-controller.yml`: existing **operational** issue-comment controller; does not call the new ledger. No permissions, event trigger or releasegate changes under B3.3 design.

## Intended trust boundary, not an endpoint specification

GitHub-hosted runner -> authenticated narrowly scoped private OVH-side claim executor -> existing MySQL database, with *no arbitrary SQL* capability. Shared hosting may restrict external MySQL connections and separate SQL principals; **verify account-specific capabilities before committing to topology**. Prefer a trusted non-public execution path with access controls independent of URL secrecy. Do not expose a web-accessible claim route or deploy any PHP endpoint under this PR.

An executor must independently validate immutable approved owner evidence, canonical issue/comment/release/SHA tuple, allowlisted action, short request lifetime, cryptographic origin authentication, nonce binding, exact target release, and authenticated post-write readback. Never accept caller-provided `dispatch_allowed`; only the separate existing releasegate may decide dispatch after its own authorizations. Never expose DB credentials, token-bearing URLs or signature material in logs/artifacts. A shared full-privilege PHP DB account is **not least privilege**; explicitly record and review this residual risk.

## Failure-state model

- Invalid signature, wrong origin/repository/owner, expired request, unknown payload fields, mismatching SHA or release, invalid nonce -> **REJECT** with no claim or dispatch.
- Duplicate comment, duplicate release ID, changed SHA under same release ID -> **BLOCK** (three independent UNIQUE constraints).
- Database unavailable, timeout or ambiguous commit -> **UNKNOWN/BLOCK**; no automatic retry, no dispatch; human reconciliation only.
- Two concurrent requests -> at most one claim insert, no second dispatch.
- Crash after commit before response -> consumed claim and manual reconciliation.
- Restored full DB snapshot -> old claim history may disappear; do not authorize claims or dispatch without an **independent durable high-water/audit authority outside the restored database**, or equivalent proven monotonic protection. Merely excluding claims from application snapshots is insufficient.

## Delivery gates

1. Evidence of OVH shared-hosting runtime connectivity, actual permissions and private ingress feasibility; no assumptions based on CI.
2. A tested offline/isolated request-validation contract and concurrency/timeout/crash/restore cases, grounded in the existing contract modules.
3. Independently protected replay record that survives full DB rollback, including recovery drills, documented operator actions and retention.
4. Security review of authentication, minimal granted access (or explicitly accepted full-privilege risk), rate limiting, observability and secret rotation.
5. Separate **exact-SHA** approval for any runtime code, ingress, credential, database, permission, controller dispatch or releasegate changes. Green CI and a PR merge alone do not authorize any of these.

No new runtime module, public endpoint, SQL mutation, deployment or workflow dispatch in this design.
