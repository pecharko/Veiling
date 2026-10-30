"""Phase C2 offline installation proof classifier; inert and fail closed.

Only accepts independently acquired trusted REST payloads. A successful result
means configuration review, never permission to issue a GitHub mutation.
"""
REPO = "pecharko/Veiling"
INSTALLATION = 176547610
APP_SLUG = "41lokeren-phase-c-writer"
EXPECTED = {"contents": "write", "metadata": "read"}


def review_installation(app, installation, repositories):
    deny = {"decision": "DENY", "write_allowed": False,
            "network_allowed": False, "activation_allowed": False,
            "production_allowed": False}
    if any(type(x) is not dict for x in (app, installation, repositories)):
        return dict(deny, reason="Missing trusted REST response")
    if app.get("slug") != APP_SLUG:
        return dict(deny, reason="Unexpected App identity")
    if type(installation.get("id")) is not int or installation["id"] != INSTALLATION:
        return dict(deny, reason="Unexpected installation ID")
    if installation.get("app_slug") != APP_SLUG:
        return dict(deny, reason="App/installation identity mismatch")
    if installation.get("repository_selection") != "selected":
        return dict(deny, reason="Repository selection must be explicit")
    perms = installation.get("permissions")
    if type(perms) is not dict or perms != EXPECTED:
        return dict(deny, reason="Unexpected permission scope")
    repos = repositories.get("repositories")
    if type(repos) is not list or len(repos) != 1 or type(repos[0]) is not dict:
        return dict(deny, reason="Exactly one repository required")
    if repos[0].get("full_name") != REPO:
        return dict(deny, reason="Installation repository mismatch")
    return dict(deny, decision="REVIEW_ONLY",
                reason="Structure matches; token provenance, REST identity and freshness not proven")


def execute(*_args, **_kwargs):
    return {"decision": "DENY", "write_allowed": False,
            "network_allowed": False, "operations": []}
