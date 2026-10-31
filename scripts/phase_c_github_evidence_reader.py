"""Read-only Phase C GitHub evidence collector; explicitly cannot write.

Dependencies are injected. A trusted future executor must supply an HTTP client
that authenticates GitHub API responses and enforces a read-only credential.
"""
from scripts.phase_c_owner_evidence import check_github_owner_evidence


def collect_and_check(api_get, pr_number, issue_number, comment_id, auth):
    """Return an advisory verdict. Never call POST, PATCH, PUT or DELETE."""
    if not all(type(n) is int and n > 0 for n in (pr_number, issue_number, comment_id)):
        return {"verified": False, "write_allowed": False, "reason": "Invalid IDs"}
    if not callable(api_get):
        return {"verified": False, "write_allowed": False, "reason": "Missing reader"}
    root = "/repos/pecharko/Veiling"
    paths = (
        root + "/pulls/" + str(pr_number),
        root + "/issues/" + str(issue_number),
        root + "/issues/comments/" + str(comment_id),
    )
    try:
        pr, issue, comment = (api_get(path) for path in paths)
        if not isinstance(pr, dict) or not isinstance(issue, dict) or not isinstance(comment, dict):
            raise ValueError("Malformed GitHub API response")
        head = pr.get("head", {})
        if not isinstance(head, dict):
            raise ValueError("Malformed head")
        ref = head.get("sha")
        if not isinstance(ref, str) or len(ref) != 40:
            raise ValueError("Invalid SHA")
        from urllib.parse import quote
        file_path = "tests/phase_c_sandbox_test.php"
        contents = api_get(root + "/contents/" + file_path + "?ref=" + quote(ref, safe=""))
        result = check_github_owner_evidence(pr, issue, comment, auth, contents)
    except (ValueError, TypeError, KeyError, OSError):
        return {"verified": False, "write_allowed": False, "reason": "Reader rejected evidence"}
    result["write_allowed"] = False
    return result
