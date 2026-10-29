"""Fail-closed *static* regression guard for currently inert Phase C modules.

This is defense in depth, NOT a proof of runtime safety or authorization to
activate a GitHub writer. It reads checked-out source and workflow files only,
and never opens a network connection, reads a token, or changes any file.
"""
import ast
from pathlib import Path
import re
import sys

MODULES = (
    "scripts/phase_c_owner_evidence.py",
    "scripts/phase_c_github_evidence_reader.py",
    "scripts/phase_c_atomic_refs_plan.py",
    "scripts/phase_c_readonly_candidate_verifier.py",
    "scripts/phase_c_github_readonly_client.py",
    "scripts/phase_c_atomic_ref_reconciliation.py",
    "scripts/phase_c_complete_readonly_audit.py",
)
WORKFLOW = ".github/workflows/php-lint.yml"
CLIENT = "scripts/phase_c_github_readonly_client.py"
CI_MARKER = "python3 scripts/phase_c_readonly_static_gate.py"
DANGEROUS_IMPORTS = {
    "os", "subprocess", "socket", "requests", "http", "httpx", "aiohttp",
    "ftplib", "paramiko", "shutil", "ctypes", "importlib",
}
DANGEROUS_CALLS = {
    "exec", "eval", "getattr", "setattr", "delattr", "__import__",
    "open", "execfile", "input", "urlopen", "Popen", "run", "system",
    "post", "put", "patch", "delete", "request", "graphql",
    "dispatch_workflow", "merge_pull_request", "create_file", "update_file",
    "create_ref", "update_ref",
}
PRIVILEGED_FLAGS = {
    "write_allowed", "can_write", "merge_allowed", "can_merge",
    "dispatch_allowed", "can_dispatch", "retry_allowed",
    "ready_for_activation", "production_authorized",
}
ALLOWED_IMPORTS = {
    "base64", "binascii", "hashlib", "re", "json",
    "urllib.parse", "urllib.error", "urllib.request",
}
SAFE_WORKFLOW_PERMISSIONS = {"contents: read"}

# Pin the *structure* of the dedicated GET path allowlist. Equivalent
# whitespace changes are fine; new endpoints require an explicit security
# review and a change to this independent policy snapshot.
CLIENT_PATH_EXPRESSION = """
tuple(re.compile(p) for p in (
    re.escape(ROOT),
    re.escape(ROOT) + r"/pulls/" + ID,
    re.escape(ROOT) + r"/issues/" + ID,
    re.escape(ROOT) + r"/issues/comments/" + ID,
    re.escape(ROOT + "/contents/" + TARGET) + r"\?ref=" + SHA,
    re.escape(ROOT) + r"/git/commits/" + SHA,
    re.escape(ROOT) + r"/git/trees/" + SHA + r"\?recursive=1",
    re.escape(ROOT) + r"/git/blobs/" + SHA,
    re.escape(ROOT) + r"/git/ref/heads/main",
    re.escape(ROOT) + r"/git/ref/heads/" + BRANCH,
    re.escape(ROOT) + r"/git/ref/tags/phase-c-once-issue-" + ID,
))
"""
CLIENT_NONCE_EXPRESSION = """
re.compile(re.escape(ROOT) + r"/git/ref/tags/phase-c-once-issue-" + ID)
"""
EXPECTED_CLIENT_CONSTANTS = {
    "API": "https://api.github.com",
    "ROOT": "/repos/pecharko/Veiling",
    "TARGET": "tests/phase_c_sandbox_test.php",
    "ID": r"[1-9][0-9]*",
    "SHA": r"[a-f0-9]{40}",
    "BRANCH": r"test/phase-c-[a-z0-9-]{1,40}",
}


def _assignment_node(tree, name):
    matches = [
        node.value for node in tree.body
        if isinstance(node, ast.Assign) and any(
            isinstance(target, ast.Name) and target.id == name
            for target in node.targets
        )
    ]
    return matches[0] if len(matches) == 1 else None


def _same_expression(node, expression):
    return node is not None and ast.dump(
        node, include_attributes=False
    ) == ast.dump(
        ast.parse(expression, mode="eval").body,
        include_attributes=False,
    )



def _call_name(node):
    if isinstance(node, ast.Name):
        return node.id
    if isinstance(node, ast.Attribute):
        return node.attr
    return ""


def _assignment_str(tree, name):
    for node in tree.body:
        if isinstance(node, ast.Assign) and any(
            isinstance(t, ast.Name) and t.id == name for t in node.targets
        ) and isinstance(node.value, ast.Constant):
            return node.value.value
    return None


def review_module(path, source):
    errors = []
    try:
        tree = ast.parse(source, filename=path)
    except SyntaxError:
        return [path + ": invalid Python syntax"]
    requests = []
    opens = []
    for node in ast.walk(tree):
        if isinstance(node, (ast.Import, ast.ImportFrom)):
            modules = ([alias.name for alias in node.names]
                       if isinstance(node, ast.Import) else [node.module or ""])
            for module in modules:
                if any(module == ban or module.startswith(ban + ".")
                       for ban in DANGEROUS_IMPORTS):
                    errors.append(path + ": prohibited import " + module)
                if not (module in ALLOWED_IMPORTS or module.startswith("scripts.phase_c_")):
                    errors.append(path + ": unapproved dependency " + module)
                if module.startswith("urllib.") and path != CLIENT and module != "urllib.parse":
                    errors.append(path + ": urllib transport outside dedicated reader")
                if isinstance(node, ast.ImportFrom) and module.startswith("urllib."):
                    approved_names = {
                        "urllib.request": {"Request", "build_opener", "HTTPRedirectHandler"},
                        "urllib.error": {"HTTPError", "URLError"},
                        "urllib.parse": {"quote"},
                    }.get(module, set())
                    for imported in node.names:
                        if (imported.name not in approved_names or imported.asname is not None):
                            errors.append(path + ": unreviewed urllib import or alias")
                if isinstance(node, ast.Import) and module.startswith("urllib."):
                    errors.append(path + ": use only explicit urllib imports")
        elif isinstance(node, ast.Call):
            name = _call_name(node.func)
            if name == "Request":
                requests.append(node)
                if path != CLIENT:
                    errors.append(path + ": HTTP request outside GET-only client")
                kw = {k.arg: k.value for k in node.keywords}
                if (not isinstance(kw.get("method"), ast.Constant) or
                        kw["method"].value != "GET" or "data" in kw or
                        node.args and len(node.args) != 1):
                    errors.append(path + ": request not strict GET")
            if name == "compile" and isinstance(node.func, ast.Name):
                errors.append(path + ": prohibited builtin compile")
            if name in DANGEROUS_CALLS:
                # Dedicated client has one reviewed self._opener.open call.
                if name == "open" and path == CLIENT:
                    opens.append(node)
                else:
                    errors.append(path + ": prohibited call " + name)
        elif isinstance(node, ast.Dict):
            for k, value in zip(node.keys, node.values):
                if (isinstance(k, ast.Constant) and k.value in PRIVILEGED_FLAGS and
                        isinstance(value, ast.Constant) and value.value is True):
                    errors.append(path + ": privileged flag set true: " + k.value)

    if path == CLIENT:
        if len(requests) != 1 or len(opens) != 1:
            errors.append(path + ": GET transport shape changed")
        for name, expected in EXPECTED_CLIENT_CONSTANTS.items():
            if _assignment_str(tree, name) != expected:
                errors.append(path + ": pinned GitHub client constant drift: " + name)
        if not _same_expression(
            _assignment_node(tree, "PATH_PATTERNS"), CLIENT_PATH_EXPRESSION
        ):
            errors.append(path + ": GET endpoint allowlist changed")
        if not _same_expression(
            _assignment_node(tree, "NONCE_PATTERN"), CLIENT_NONCE_EXPRESSION
        ):
            errors.append(path + ": nonce endpoint pattern changed")
        if opens:
            op = opens[0]
            target = op.func
            if not (isinstance(target, ast.Attribute) and
                    isinstance(target.value, ast.Attribute) and
                    target.value.attr == "_opener" and
                    isinstance(target.value.value, ast.Name) and
                    target.value.value.id == "self"):
                errors.append(path + ": unreviewed open transport")
        classes = [x for x in tree.body if isinstance(x, ast.ClassDef)
                   and x.name == "GitHubReadOnlyClient"]
        if len(classes) != 1 or sorted(
            x.name for x in classes[0].body if isinstance(x, ast.FunctionDef)
        ) != ["__init__", "get"]:
            errors.append(path + ": unexpected client public methods")
    elif requests:
        errors.append(path + ": direct Request call outside client")
    return errors


def review_workflow(content):
    match = re.search(
        r"(?m)^permissions:\s*\n(?P<body>(?:[ \t]+[^\n]*\n)+)",
        content,
    )
    if not match:
        return ["CI: explicit read-only permissions missing"]
    permissions = {line.strip() for line in match.group("body").splitlines()
                   if line.strip()}
    errors = []
    if permissions != SAFE_WORKFLOW_PERMISSIONS:
        errors.append("CI: unexpected job or token permissions")
    # A job-level override can silently supersede a read-only top-level token.
    permission_declarations = re.findall(
        r"(?m)^([ \t]*)permissions\s*:", content
    )
    if permission_declarations != [""]:
        errors.append("CI: duplicate or job-level permissions override")
    checkout = re.search(
        r"(?ms)^      - name: Checkout source[ \t]*\n"
        r"(?P<body>.*?)(?=^      - name: |\Z)",
        content,
    )
    if checkout is None:
        errors.append("CI: expected source checkout missing")
    else:
        block = checkout.group("body")
        if not re.search(r"(?m)^        uses: actions/checkout@v4\s*$", block):
            errors.append("CI: checkout action changed")
        if not re.search(r"(?m)^          persist-credentials: false\s*$", block):
            errors.append("CI: checkout credentials may persist")
        if len(re.findall(r"(?m)^          persist-credentials:", block)) != 1:
            errors.append("CI: duplicate or missing checkout credential setting")
        if re.search(r"(?m)^          token\s*:", block):
            errors.append("CI: explicit checkout token override")
    if len(re.findall(
        r"(?m)^\s*uses: actions/checkout@", content
    )) != 1:
        errors.append("CI: additional or missing checkout action")
    if CI_MARKER not in content:
        errors.append("CI: Phase C static guard not executed")
    if "python3 -m unittest discover -s tests -p phase_c_readonly_static_gate_test.py -v" not in content:
        errors.append("CI: static guard regression tests not executed")
    return errors


def review_sources(sources, workflow):
    """Return actionable read-only policy findings for a source snapshot."""
    if not isinstance(sources, dict) or not isinstance(workflow, str):
        return ["Invalid code snapshot"]
    errors = []
    for path in MODULES:
        if not isinstance(sources.get(path), str):
            errors.append(path + ": code missing")
        else:
            errors.extend(review_module(path, sources[path]))
    errors.extend(review_workflow(workflow))
    return sorted(set(errors))


def check_repository(root):
    sources = {}
    errors = []
    for path in MODULES:
        try:
            sources[path] = (root / path).read_text(encoding="utf-8")
        except (OSError, UnicodeError):
            errors.append(path + ": unavailable")
    try:
        workflow = (root / WORKFLOW).read_text(encoding="utf-8")
    except (OSError, UnicodeError):
        workflow = ""
    return sorted(set(errors + review_sources(sources, workflow)))


def main():
    findings = check_repository(Path(__file__).resolve().parent.parent)
    if findings:
        for item in findings:
            print("DENY:", item)
        return 1
    print("Phase C static read-only guard passed; not proof of writer safety")
    return 0


if __name__ == "__main__":
    sys.exit(main())
