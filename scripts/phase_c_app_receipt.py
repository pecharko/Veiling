"""Pure offline validation of a GitHub App installation receipt.

This validator does not make HTTP requests, access credentials or enable writes.
An API-shaped receipt is untrusted and never authorizes a transaction.
"""
APP_ID = 5526516
INSTALLATION_ID = 176547610
ACCOUNT = "pecharko"
REPOSITORY = "pecharko/Veiling"


def inspect_receipt(installation, repositories):
    denial = {"decision": "DENY", "write_allowed": False, "retry_allowed": False}
    if type(installation) is not dict or type(repositories) is not list:
        return dict(denial, reason="Malformed response")
    if (type(installation.get("id")) is not int
            or installation["id"] != INSTALLATION_ID
            or installation.get("app_id") != APP_ID):
        return dict(denial, reason="Wrong installation or app")
    account = installation.get("account")
    if type(account) is not dict or account.get("login") != ACCOUNT:
        return dict(denial, reason="Wrong account")
    if installation.get("repository_selection") != "selected":
        return dict(denial, reason="Not repository-limited")
    perms = installation.get("permissions")
    if type(perms) is not dict or perms != {"contents": "read", "metadata": "read"}:
        return dict(denial, reason="Unexpected permissions")
    if len(repositories) != 1 or type(repositories[0]) is not dict:
        return dict(denial, reason="Wrong repository count")
    repo = repositories[0]
    if repo.get("full_name") != REPOSITORY or type(repo.get("id")) is not int:
        return dict(denial, reason="Wrong repository")
    return dict(denial, reason="Receipt verified offline; writer remains disabled",
                read_only_receipt_consistent=True)
