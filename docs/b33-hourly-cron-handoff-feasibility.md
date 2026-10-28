# B3.3 — OVH hourly Cron handoff feasibility (design-only addendum)

Baseline: `main` at `98e2ee353b5535b6d031bb15627d5609a158f545`. No deployment permission.

## Verified schedule constraint

OVH web-hosting Cron tasks can run **at most once per hour**; execution minute cannot be selected. Official source: https://docs.ovhcloud.com/en/guides/web-cloud/web-hosting/cron-tasks . This is the hosting product limit, not a GitHub workflow limit. A task also has a 60-minute runtime cap and can be disabled following repeated failures.

The offline `scripts/controller_cron_handoff_contract.py` currently enforces `MAX_TTL = 300` seconds. A GitHub-generated authorization envelope delivered asynchronously to a once-hourly poller may expire before the poller runs. **Do not merely extend the MAC time-to-live to 60+ minutes**: that widens the replay window and does not solve full-DB restore.

## Candidate architecture, not an implementation

1. GitHub-side producer prepares a bounded immutable envelope with canonical repository, issue/comment ID, release ID, exact SHA and approval evidence. Producer never sends DB credentials, raw SQL, or an instruction to dispatch.
2. An authenticated durable **transport queue** carries the request; a queue item is not proof that the approval is still valid. Queue must be private, integrity-protected, quota-bounded, and have independent append-only provenance. Selection of transport and its privileges requires a separate security decision. A GitHub-to-OVH SFTP delivery would grant broad file-write capability and must NOT be silently treated as least privilege.
3. The private CLI-only OVH Cron consumer polls no more frequently than hosting permits, verifies current independent owner/release evidence and policy afresh at processing time, authenticates the transport message, and rejects stale/ambiguous input. The five-minute request token from the existing offline contract must be **generated/refreshed at processing time under a reviewed trust model** or replaced with a separately reviewed durable message format. It cannot be replayed from old queue contents.
4. Only a separately approved transactional claimant may use `controller_release_claims`. Uncertain commits, duplicate keys, missing audit evidence, queue loss or restored state mean **BLOCK**, never automatic retry or dispatch.
5. A **separate monotonic, independently protected replay ledger** must survive a full OVH MySQL restore and must not rely solely on files on the same recoverable hosting account. Its authority, availability and retention need proof. Do not consume queued messages or issue new claims while this authority is unavailable.
6. A separate explicitly authorized dispatcher/releasegate remains outside the queue/claim consumer. A successful queue poll or DB insert does not authorize deployment.

## Required acceptance evidence before implementation

- Actual Starter Cron scheduling tested without enabling B3.3 task; measured delay and error semantics from a separately approved harmless task only.
- Exact selected transport path with authentication, key rotation, anti-tamper, file/path ownership and failure cases; no arbitrary SQL or world-readable handoff.
- Offline adversarial tests: out-of-order queue delivery, duplicate messages, timeout after commit, restart, full DB rollback, stale approvals, forged signatures, clock skew, queue overflow and exhausted storage.
- Security review and exact SHA authorization for any GitHub workflow permission, OVH cron activation, credential, DB writer, runtime code, ingress or releasegate modification.

**This addendum is documentation only.** No cron task or queue created; no database, secret, workflow or production changes.
