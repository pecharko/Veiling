# Phase C2 trusted-execution gap

This code unifies offline reviews only. No operational writer is present.

Live activation prerequisites (not granted here): authenticated GitHub
App identity and installation-scoped effective permissions; independently
retrieved sandbox head and nonce baseline; validated commit tree with exactly
one allowed file; immutable exact one-shot owner authorization; explicit
single-call atomic GraphQL CAS capability under active tag and branch
rulesets; no retry on ambiguous outcomes; independent readback of both refs.

No token is stored or loaded here. There is no environment or CLI flag that
turns a review verdict into a write. Even if an untrusted evidence object
includes owner_comment_verified=True or writer_permissions_verified=True,
the verdict is never permission to run a mutation.

Prepare an independently reviewed live adapter in a separate change only
after deciding how real owner authorization and app identity are authenticated.
