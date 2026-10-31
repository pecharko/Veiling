#!/usr/bin/env python3
"""Manually invoked, exact-scope Phase-C NEW sandbox worker.

Not wired to Actions or the deploy controller. Default mode is GET-only.
The sole mutation path requires a NEW owner GitHub comment tied to this exact
checked-out worker revision and explicit --execute. No retry or fallback.
No credentials or response bodies containing credentials are ever printed.
"""
import argparse
import base64
import hashlib
import http.client
import json
import re
import os
import ssl
import subprocess
import sys

REPO = "pecharko/Veiling"
BASE = "/repos/" + REPO
APP_ID = 5526516
INSTALLATION = 176547610
SLUG = "41lokeren-phase-c-writer"
HEAD = "2ee5419b4642b2108414c8b9d4f193298004b23a"
CANDIDATE = "73be8a820e0801c9ceba3b548da91a5732325ec4"
BRANCH = "refs/heads/test/phase-c-new-isolated-20261030"
NONCE = None  # Defined ONLY from a future new owner-created issue number
TARGET = "tests/phase_c_new_repair_test.php"
ORIGINAL_BLOB = "76fda06da4013582f240ffc7c35ee94dbb502e67"
NEW_BLOB = "3f48693b3092785554192ad766940565c9435a17"
NEW_SHA256 = "b743ff7e7006659676f24bf7626d95cb8a75a52b4c681e74a2629924d221059d"
REPO_NODE = "R_kgDOVNLxvg"
OWNER_ID = 343879284
ISSUE = None
ORIGINAL_BASE = "3a0d61c07832df05eba4f377f446fd9e98b4f9be"
BRANCH_RULE_INCLUDES = ["refs/heads/test/phase-c-writer-fixture-20261029", BRANCH]
EXPECTED_PERMISSIONS = {"contents": "write", "metadata": "read"}
DENY = {"decision": "DENY", "executed": False, "retry_allowed": False}


def configure_issue(issue_number):
    """Set exact new one-shot issue scope; rejects every historic or PR ID."""
    global ISSUE, NONCE
    require(type(issue_number) is int and issue_number > 477,
            "A NEW owner-created issue after PR477 is required")
    require(ISSUE in (None, issue_number), "Cannot change issue scope in one process")
    ISSUE = issue_number
    NONCE = "refs/tags/phase-c-once-issue-" + str(issue_number)


class Refused(Exception):
    pass


def require(ok, why):
    if not ok:
        raise Refused(why)


def _api(token, path, method="GET", payload=None):
    """Fixed api.github.com origin, no redirects, no automatic retries."""
    require(isinstance(token, str) and len(token) > 10, "Credential unavailable")
    require(path.startswith("/") and "://" not in path and "\n" not in path,
            "Unsafe API path")
    require(method in ("GET", "POST"), "Unsafe HTTP method")
    require(payload is None or method == "POST", "Unexpected request body")
    if method == "POST":
        if path == "/app/installations/" + str(INSTALLATION) + "/access_tokens":
            require(payload == {"repositories": ["Veiling"],
                                "permissions": EXPECTED_PERMISSIONS},
                    "Token request scope drift")
        elif path == "/graphql":
            require(type(payload) is dict and ISSUE is not None and NONCE is not None,
                    "Unconfigured GraphQL request")
            variables = payload.get("variables")
            data = variables.get("input") if type(variables) is dict else None
            cid = data.get("clientMutationId") if type(data) is dict else None
            prefix = "phase-c-new-" + str(ISSUE) + "-"
            matched = (re.fullmatch(re.escape(prefix) + r"([1-9][0-9]*)-" +
                       re.escape(HEAD[:12]), cid) if type(cid) is str else None)
            require(matched is not None
                    and payload == mutation(int(matched.group(1))),
                    "Mutation must contain only approved sandbox branch and new nonce")
        else:
            raise Refused("No other HTTP POST endpoint is permitted")
    headers = {"Authorization": "Bearer " + token,
               "Accept": "application/vnd.github+json",
               "X-GitHub-Api-Version": "2022-11-28",
               "User-Agent": "phase-c-new-local-once",
               "Content-Type": "application/json"}
    body = json.dumps(payload, separators=(",", ":")) if payload is not None else None
    connection = http.client.HTTPSConnection("api.github.com", timeout=10,
                                               context=ssl.create_default_context())
    try:
        connection.request(method, path, body=body, headers=headers)
        response = connection.getresponse()
        status = response.status
        raw = response.read(1024 * 1024 + 1)
        require(len(raw) <= 1024 * 1024, "Oversized GitHub response")
        value = json.loads(raw.decode("utf-8")) if raw else {}
        require(type(value) is dict, "Non-object GitHub response")
        return status, value
    finally:
        connection.close()


def get(token, path):
    status, value = _api(token, path)
    require(status == 200, "Authenticated GitHub GET failed: " + path + " status=" + str(status))
    return value


def owner_verified(value):
    return (type(value) is dict and type(value.get("user")) is dict
            and value["user"].get("login") == "pecharko"
            and value["user"].get("id") == OWNER_ID)


def approval_phrase(adapter_sha):
    require(ISSUE is not None and NONCE is not None, "Issue scope missing")
    return ("PHASE-C-NEW-ONCE repo=" + REPO + " pr=475 issue=" + str(ISSUE)
            + " old=" + HEAD + " new=" + CANDIDATE
            + " blob=" + ORIGINAL_BLOB + " target=" + TARGET
            + " nonce=" + NONCE + " worker=" + adapter_sha)


def _exact_installation(value):
    """Validate GitHub-identified App, owner, selection and permissions."""
    return (type(value) is dict
            and type(value.get("id")) is int and value["id"] == INSTALLATION
            and type(value.get("app_id")) is int and value["app_id"] == APP_ID
            and value.get("app_slug") == SLUG
            and value.get("repository_selection") == "selected"
            and value.get("permissions") == EXPECTED_PERMISSIONS
            and value.get("target_type") == "User"
            and type(value.get("target_id")) is int
            and value["target_id"] == OWNER_ID
            and type(value.get("account")) is dict
            and value["account"].get("id") == OWNER_ID
            and value["account"].get("login") == "pecharko"
            and "suspended_at" in value and value["suspended_at"] is None)


def verify_app(app_jwt):
    """Three authenticated GETs, no token minting, on the intended App."""
    app = get(app_jwt, "/app")
    require(type(app.get("id")) is int and app["id"] == APP_ID
            and app.get("slug") == SLUG, "Unexpected authenticated GitHub App")
    inst = get(app_jwt, "/app/installations/" + str(INSTALLATION))
    require(_exact_installation(inst), "Writer installation identity or scope mismatch")
    # A selected-repositories installation need not include this repository.
    # The GitHub App JWT must independently prove its installation on Veiling.
    repo_installation = get(app_jwt, BASE + "/installation")
    require(_exact_installation(repo_installation),
            "Authenticated App is not installed on the exact repository")
    return inst


def verify_ref_states(token):
    require(ISSUE is not None and NONCE is not None, "Issue scope missing")
    head = get(token, BASE + "/git/ref/" + BRANCH.removeprefix("refs/"))
    require(head.get("ref") == BRANCH and
            type(head.get("object")) is dict and
            head["object"].get("sha") == HEAD, "Sandbox branch drift")
    nonce_status, _ = _api(token, BASE + "/git/ref/" + NONCE.removeprefix("refs/"))
    require(nonce_status == 404, "Nonce must be absent, observable or it is spent")


def verify_static_evidence(owner_token):
    repo = get(owner_token, BASE)
    require(repo.get("full_name") == REPO and repo.get("node_id") == REPO_NODE,
            "Repository mismatch")
    pull = get(owner_token, BASE + "/pulls/475")
    require(pull.get("state") == "open" and pull.get("draft") is True
            and pull.get("head", {}).get("sha") == HEAD
            and pull.get("head", {}).get("repo", {}).get("full_name") == REPO
            and pull.get("base", {}).get("repo", {}).get("full_name") == REPO
            and pull.get("base", {}).get("ref") == "main"
            and pull.get("base", {}).get("sha") == ORIGINAL_BASE
            and pull.get("head", {}).get("ref") == BRANCH.removeprefix("refs/heads/"),
            "Sandbox PR has drifted or is a fork")
    issue = get(owner_token, BASE + "/issues/" + str(ISSUE))
    body = ("PHASE-C-NEW-PLAN pr=475 issue=" + str(ISSUE)
            + " head=" + HEAD + " blob=" + ORIGINAL_BLOB)
    require(owner_verified(issue) and issue.get("number") == ISSUE
            and issue.get("state") == "open" and issue.get("body") == body,
            "New owner issue missing or drifted")
    verify_rules(owner_token)
    comp = get(owner_token, BASE + "/compare/" + HEAD + "..." + CANDIDATE)
    require(comp.get("status") == "ahead" and comp.get("ahead_by") == 1
            and comp.get("behind_by") == 0
            and len(comp.get("files", [])) == 1, "Candidate comparison drift")
    file = comp["files"][0]
    require(file.get("filename") == TARGET and file.get("status") == "modified"
            and file.get("sha") == NEW_BLOB
            and file.get("additions") == 1 and file.get("deletions") == 1,
            "Candidate changes unexpected file")
    commit = get(owner_token, BASE + "/git/commits/" + CANDIDATE)
    require(len(commit.get("parents", [])) == 1
            and commit["parents"][0].get("sha") == HEAD,
            "Candidate parent mismatch")
    for blob_sha, should_have_typo in ((ORIGINAL_BLOB, True), (NEW_BLOB, False)):
        blob = get(owner_token, BASE + "/git/blobs/" + blob_sha)
        require(blob.get("sha") == blob_sha and blob.get("encoding") == "base64",
                "Blob evidence invalid")
        content = base64.b64decode(blob.get("content", ""), validate=False)
        require(content.startswith(b"<?php") and len(content) <= 2048,
                "Unexpected sandbox blob")
        if should_have_typo:
            original = content
        else:
            require(hashlib.sha256(content).hexdigest() == NEW_SHA256,
                    "Candidate digest mismatch")
            fixed = content
    require(original == b"<?php\nfunction phase_c_new_repair_fixture(): bool {\n    return flase;\n}\n"
            and original.replace(b"return flase;", b"return false;", 1) == fixed,
            "Candidate is not exact typo repair")
    verify_ref_states(owner_token)


def verify_rules(token):
    """Fail closed on effective ruleset state of the ACTUAL caller identity."""
    for rule_id, target, included, types in (
        (26113993, "branch", BRANCH_RULE_INCLUDES, {"deletion", "non_fast_forward"}),
        (26113833, "tag", ["refs/tags/phase-c-once-issue-*"], {"update", "deletion"}),
    ):
        rule = get(token, BASE + "/rulesets/" + str(rule_id))
        ref_condition = rule.get("conditions", {}).get("ref_name", {})
        rules = rule.get("rules", [])
        require(rule.get("enforcement") == "active" and rule.get("target") == target
                and rule.get("source") == REPO and rule.get("bypass_actors") == []
                and rule.get("current_user_can_bypass") == "never"
                and ref_condition.get("include") == included
                and ref_condition.get("exclude") == []
                and len(rules) == len(types)
                and {r.get("type") for r in rules} == types,
                "Ruleset protection or effective caller bypass unverified")


def verify_checkout():
    proc = subprocess.run(["git", "rev-parse", "HEAD"], capture_output=True,
                          text=True, check=True)
    sha = proc.stdout.strip()
    active = subprocess.run(["git", "branch", "--show-current"], capture_output=True,
                            text=True, check=True)
    require(active.stdout.strip() == "main", "Execution only from reviewed main branch")
    require(len(sha) == 40 and all(c in "0123456789abcdef" for c in sha),
            "Invalid local revision")
    dirty = subprocess.run(["git", "status", "--porcelain", "--untracked-files=all"],
                           capture_output=True, text=True, check=True)
    require(not dirty.stdout.strip(), "Local checkout is not clean")
    return sha


def verify_fresh_approval(owner_token, comment_id, checked_out_sha):
    require(type(comment_id) is int and comment_id > 0,
            "New owner comment ID required")
    issue = get(owner_token, BASE + "/issues/" + str(ISSUE))
    comment = get(owner_token, BASE + "/issues/comments/" + str(comment_id))
    require(owner_verified(issue) and issue.get("number") == ISSUE
            and owner_verified(comment)
            and comment.get("body") == approval_phrase(checked_out_sha)
            and comment.get("issue_url") ==
                "https://api.github.com" + BASE + "/issues/" + str(ISSUE)
            and type(comment.get("created_at")) is str
            and type(issue.get("created_at")) is str
            and comment["created_at"] > issue["created_at"],
            "Exact fresh owner approval missing or predated the issue")


def mint_exact_writer_token(app_jwt):
    status, value = _api(app_jwt, "/app/installations/" + str(INSTALLATION)
                         + "/access_tokens", "POST",
                         {"repositories": ["Veiling"],
                          "permissions": EXPECTED_PERMISSIONS})
    require(status == 201 and type(value.get("token")) is str,
            "Cannot mint exact installation token")
    require(value.get("permissions") == EXPECTED_PERMISSIONS,
            "Minted token permission mismatch")
    token = value["token"]
    repos = get(token, "/installation/repositories")
    require(repos.get("total_count") == 1
            and len(repos.get("repositories", [])) == 1
            and repos["repositories"][0].get("full_name") == REPO,
            "Minted token scope mismatch")
    return token


def mutation(comment_id):
    return {"query": "mutation PhaseCAtomicRefs($input: UpdateRefsInput!) { "
            "updateRefs(input: $input) { clientMutationId } }",
            "variables": {"input": {
                "repositoryId": REPO_NODE,
                "clientMutationId": "phase-c-new-" + str(comment_id)
                                    + "-" + HEAD[:12],
                "refUpdates": [
                    {"name": NONCE, "beforeOid": "0" * 40, "afterOid": HEAD,
                     "force": False},
                    {"name": BRANCH, "beforeOid": HEAD, "afterOid": CANDIDATE,
                     "force": False},
                ]}}}


def run(app_jwt, owner_token, execute=False, comment_id=None):
    """No POST in default mode; on execute one token mint, one GraphQL POST."""
    verify_app(app_jwt)
    verify_static_evidence(owner_token)
    if not execute:
        return {"decision": "READ_ONLY_VERIFIED", "executed": False,
                "retry_allowed": False}
    checkout_sha = verify_checkout()
    verify_fresh_approval(owner_token, comment_id, checkout_sha)
    main_ref = get(owner_token, BASE + "/git/ref/heads/main")
    require(main_ref.get("object", {}).get("sha") == checkout_sha,
            "Remote main no longer equals the reviewed local worker revision")
    writer = mint_exact_writer_token(app_jwt)
    # Independently check effective rules with the ACTUAL installation writer token.
    # GitHub may return 403 here: fail closed, NEVER bypass rules or retry.
    verify_rules(writer)
    verify_ref_states(writer)
    request = mutation(comment_id)
    # One and only one GraphQL mutation request. No network retries on any error.
    # Any connection failure after an attempted POST has an ambiguous outcome.
    # Investigate read-only, NEVER retry even if a response was not received.
    try:
        result_status, response = _api(writer, "/graphql", "POST", request)
    except (OSError, ValueError, Refused, http.client.HTTPException):
        result_status, response = 0, {}
    success = (result_status == 200 and not response.get("errors")
               and type(response.get("data")) is dict
               and type(response["data"].get("updateRefs")) is dict
               and response["data"]["updateRefs"].get("clientMutationId")
               == request["variables"]["input"]["clientMutationId"])
    try:
        branch_status, branch_read = _api(owner_token, BASE + "/git/ref/"
                                          + BRANCH.removeprefix("refs/"))
        nonce_status, nonce_read = _api(owner_token, BASE + "/git/ref/"
                                        + NONCE.removeprefix("refs/"))
        confirmed = (branch_status == 200 and nonce_status == 200
                     and branch_read.get("object", {}).get("sha") == CANDIDATE
                     and nonce_read.get("object", {}).get("sha") == HEAD)
    except (OSError, ValueError, Refused, http.client.HTTPException):
        confirmed = False
    if success and confirmed:
        try:
            confirmed = verify_full_readback(owner_token)
        except (OSError, ValueError, Refused, http.client.HTTPException):
            confirmed = False
    return {"decision": "READBACK_CONFIRMED" if success and confirmed
            else "AMBIGUOUS_STOP_NO_RETRY", "executed": True,
            "retry_allowed": False, "readback_confirmed": confirmed}


def verify_full_readback(token):
    """Independently re-read exact candidate, file and PR after one attempted POST."""
    pull = get(token, BASE + "/pulls/475")
    require(pull.get("state") == "open" and pull.get("draft") is True
            and pull.get("merged") is not True
            and pull.get("head", {}).get("sha") == CANDIDATE,
            "Postwrite draft PR mismatch")
    commit = get(token, BASE + "/git/commits/" + CANDIDATE)
    require(len(commit.get("parents", [])) == 1
            and commit["parents"][0].get("sha") == HEAD,
            "Postwrite commit ancestry mismatch")
    compare = get(token, BASE + "/compare/" + HEAD + "..." + CANDIDATE)
    files = compare.get("files")
    require(compare.get("ahead_by") == 1 and compare.get("behind_by") == 0
            and type(files) is list and len(files) == 1
            and files[0].get("filename") == TARGET
            and files[0].get("status") == "modified"
            and files[0].get("sha") == NEW_BLOB,
            "Postwrite change scope mismatch")
    blob = get(token, BASE + "/git/blobs/" + NEW_BLOB)
    require(blob.get("sha") == NEW_BLOB and blob.get("encoding") == "base64",
            "Postwrite blob mismatch")
    raw = base64.b64decode(blob.get("content", ""), validate=True)
    require(hashlib.sha256(raw).hexdigest() == NEW_SHA256,
            "Postwrite byte digest mismatch")
    return True


def main():
    parser = argparse.ArgumentParser(description="PR475 local one-shot GitHub App worker")
    parser.add_argument("--issue-number", type=int, required=True,
                        help="New owner-created issue, not old issue #427")
    parser.add_argument("--execute", action="store_true",
                        help="Needs separate exact owner approval; can mutate ONLY PR475 sandbox")
    parser.add_argument("--approval-comment-id", type=int)
    args = parser.parse_args()
    if bool(args.execute) != bool(args.approval_comment_id is not None):
        parser.error("--execute and --approval-comment-id must be supplied together")
    try:
        configure_issue(args.issue_number)
        app_jwt = os.environ["PHASE_C_APP_JWT"]
        owner_token = os.environ["PHASE_C_OWNER_READ_TOKEN"]
        outcome = run(app_jwt, owner_token, args.execute, args.approval_comment_id)
    except (KeyError, Refused, OSError, ValueError, subprocess.CalledProcessError) as exc:
        # Refused reasons contain no token or server response.
        print(json.dumps(dict(DENY, reason="Preflight or transport failed; no retry")),
              file=sys.stderr)
        return 1
    print(json.dumps(outcome))
    return 0 if outcome["decision"] in ("READ_ONLY_VERIFIED", "READBACK_CONFIRMED") else 1


if __name__ == "__main__":
    sys.exit(main())
