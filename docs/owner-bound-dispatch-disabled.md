# Owner-bound dispatch controller — disabled design boundary

The original review remains **offline consistency review only**. The separately
implemented integration in `scripts/owner_bound_dispatch.py` is dormant: its
public `execute()` entrypoint always returns `DISABLED`, before reading evidence,
opening storage or constructing a transport. No workflow imports it, and no
environment variable, input JSON or CLI flag can activate this revision.
Do not treat a user-provided JSON ticket, nonce, GitHub issue comment, or boolean proof as an
authenticated owner authorization. A non-atomic read of prior dispatches is insufficient to
prevent two dispatches; the existing production workflow concurrency alone does not guarantee
at-most-once *invocation*.

## Acceptance criteria for a separately authorized live adapter

1. **Default disabled**; separate exact approval for activation, credentials, permissions, and deployment.
2. Verify authenticated owner identity using a trusted provider outside the input JSON and bind
   one-use consent to repository, immutable merged main SHA, reviewed manifest digest, release
   ID, all allowed file paths, and latest successful independent main-push CI.
3. Independently re-fetch/validate the manifest and release-file allowlist; check source hashes
   and remote SHA baselines before dispatch. Recheck main immediately before any attempted write.
4. Atomically reserve one-use nonce in a durable trusted store before a single dispatch attempt.
   On ambiguous network/API result: STOP, NO RETRY; reconcile read-only.
5. Use only the reviewed `shared-ovh-release.yml` target and exactly its `release_id` and
   `DEPLOY-<id>-<main SHA>` confirmation; no arbitrary workflow target or ref.
6. Preserve production environment protections, pinned SFTP host key, fresh drift gate,
   per-file source/remote SHA checks, backup, rollback, readback, and evidence.
7. Independent evidence review must not be conflated with runner readback or browser acceptance.

The offline review module always returns `dispatch_allowed: false`. Passing tests is not
proof of live owner authentication, production storage durability or OVH access.
See [the integration and activation boundary](owner-bound-dispatch-integration.md)
for the implemented sequence, tests, trust assumptions and reconciliation limits.
