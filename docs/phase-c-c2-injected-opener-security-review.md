# Phase C2 security review: callable-injected mutation bypass

The public `submit_atomic` method in PR #450 denied writes, but its
`_post_once_injected` function was importable and accepted arbitrary Python
callables. Calling it with a real URL opener and bearer token could therefore
send a real GraphQL `updateRefs` mutation without independently authenticated
owner approval, App-specific ruleset proof, candidate provenance or durable
authorization.

This was an **unsafe execution surface** despite the method being private by
convention and CI only injecting fake openers. `_post_once_injected` now
returns DENY for every call, including legacy imports. The module has no
network import, HTTP sender, token reader, activation switch or retry method.
Offline scope analysis remains available through `inspect_atomic_envelope`,
which never authorizes actions.

Security review conclusions:
- Existing offline evidence booleans and ACK values cannot grant permission.
- A future actual writer must be separately implemented with authenticated,
  out-of-band installation identity and immutable one-shot approval, and must
  be independently reviewed and authorized before it can send even one write.
- No CI, sandbox mutation, token, rights, releasegate or production change is
  introduced by this correction.
