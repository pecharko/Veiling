# Phase C2 integrated execution boundary

The local operator has verified repository-restricted read-only App
credentials for installation 176547610. The App permissions were also
manually confirmed as Contents:read and Metadata:read.

This integration connects only (a) an authenticated fixed GraphQL repository
identity read and (b) the immutable offline combined preflight. It returns
READ_ONLY_REVIEW, never permission. The write entrypoint is unconditionally
DENY. No C2 writer can operate from repository code or GitHub Actions.

Before any **separate** write-capable implementation and first sandbox
transaction, independently verify App identity and effective rulesets with
App-specific evidence, proposed candidate commit parent/one-path diff and
immutable authorization. Do not rely on injected owner/permission booleans.
GitHub Contents:write would require the owner's distinct explicit permission
change. Real execution needs separate exact PR/nonce/head/file/commit scoped
authorization and a carefully reviewed, default-disabled sender with
no retry on ambiguous responses, independent post-write readback and no
production access. Neither changing permissions nor first write is authorized
by this change.
