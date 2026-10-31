# C2 Independent postwrite readback contract

This code is verification only and does not activate the writer. A real
one-shot sender, if separately approved and security-reviewed, must stop
after any ambiguous outcome and independently fetch both exact refs with
authenticated GitHub GET calls. The readback comparison only verifies that
the returned branch and nonce values match the intended state. It cannot
prove atomicity, App identity, owner authorization or that a specific
request caused the observed state. Fetch authentication and REST status
codes must be verified *before* passing parsed objects to this comparator.

Expected branch ref points to candidate commit
11ead85e1a3d0086166da665a1a6d4bdf25477d7;
expected tag ref points to original sandbox head
2595a63c6cd575dbbb7f29f293ccccaccc140553.

A missing tag, unexpected branch or incompatible GitHub object yields
DENY. Never retry mutation automatically. Never merge sandbox PR #426.
No CI workflow may acquire App credentials or mutate refs.
