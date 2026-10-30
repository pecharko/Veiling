#!/usr/bin/env python3
"""Manually invoked, exact-scope Phase-C2 local worker.

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
import os
import ssl
import subprocess
import sys

REPO = "pecharko/Veiling"
BASE = "/repos/" + REPO
APP_ID = 5526516
INSTALLATION = 176547610
SLUG = "41lokeren-phase-c-writer"
HEAD = "2595a63c6cd575dbbb7f29f293ccccaccc140553"
CANDIDATE = "11ead85e1a3d0086166da665a1a6d4bdf25477d7"
BRANCH = "refs/heads/test/phase-c-writer-fixture-20261029"
NONCE = "refs/tags/phase-c-once-issue-427"
TARGET = "tests/phase_c_sandbox_test.php"
ORIGINAL_BLOB = "a16bb38acbde1422389e1fcb4835c20688f594b3"
NEW_BLOB = "9bb4e9774e6244c143975c00bcac4826d4895317"
NEW_SHA256 = "013a57991e6285797587766f1bfb422d5f27045a1ca91531dd772b44958e9323"
REPO_NODE = "R_kgDOVNLxvg"
OWNER_ID = 343879284
OLD_COMMENT = 6493461101
ISSUE = 427
EXPECTED_PERMISSIONS = {"contents": "write", "metadata": "read"}
DENY = {"decision": "DENY", "executed": False, "retry_allowed": False}


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
    headers = {"Authorization": "Bearer " + token,
               "Accept": "application/vnd.github+json",
               "X-GitHub-Api-Version": "2022-11-28",
               "User-Agent": "phase-c2-local-once",
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
    return ("PHASE-C2-LIVE-ONCE repo=" + REPO + " pr=426 issue=427"
            + " old=" + HEAD + " new=" + CANDIDATE
            + " nonce=" + NONCE + " adapter=" + adapter_sha)


def verify_app(app_jwt):
    app = get(app_jwt, "/app")
    require(type(app.get("id")) is int and app["id"] == APP_ID
            and app.get("slug") == SLUG, "Unexpected authenticated GitHub App")
    inst = get(app_jwt, "/app/installations/" + str(INSTALLATION))
    require(inst.get("id") == INSTALLATION and inst.get("app_id") == APP_ID
            and inst.get("app_slug") == SLUG
            and inst.get("repository_selection") == "selected"
            and inst.get("permissions") == EXPECTED_PERMISSIONS,
            "Writer installation identity or permissions mismatch")
    return inst


def verify_ref_states(token):
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
    pull = get(owner_token, BASE + "/pulls/426")
    require(pull.get("state") == "open" and pull.get("draft") is True
            and pull.get("head", {}).get("sha") == HEAD
            and pull.get("head", {}).get("repo", {}).get("full_name") == REPO
            and pull.get("base", {}).get("repo", {}).get("full_name") == REPO,
            "Sandbox PR has drifted or is a fork")
    issue = get(owner_token, BASE + "/issues/427")
    old = get(owner_token, BASE + "/issues/comments/" + str(OLD_COMMENT))
    body = ("PHASE-C-SANDBOX-ONCE pr=426 head=" + HEAD
            + " blob=" + ORIGINAL_BLOB)
    require(owner_verified(issue) and owner_verified(old)
            and issue.get("state") == "open" and issue.get("body") == body
            and old.get("body") == body, "Owner issue/comment not authenticated")
    for rule_id, target, ref, types in (
        (26113993, "branch", BRANCH, {"deletion", "non_fast_forward"}),
        (26113833, "tag", "refs/tags/phase-c-once-issue-*", {"update", "deletion"}),
    ):
        rule = get(owner_token, BASE + "/rulesets/" + str(rule_id))
        ref_condition = rule.get("conditions", {}).get("ref_name", {})
        require(rule.get("enforcement") == "active" and rule.get("target") == target
                and rule.get("source") == REPO and rule.get("bypass_actors") == []
                and ref_condition.get("include") == [ref]
                and ref_condition.get("exclude") == []
                and {r.get("type") for r in rule.get("rules", [])} == types,
                "Ruleset protection unverified")
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
    require(original.count(b"return flase;") == 1
            and original.replace(b"return flase;", b"return false;", 1) == fixed,
            "Candidate is not exact typo repair")
    verify_ref_states(owner_token)


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
    require(type(comment_id) is int and comment_id > OLD_COMMENT,
            "New independent owner comment required after review")
    comment = get(owner_token, BASE + "/issues/comments/" + str(comment_id))
    require(owner_verified(comment) and comment.get("body") == approval_phrase(checked_out_sha)
            and comment.get("issue_url") == "https://api.github.com" + BASE + "/issues/427",
            "Exact fresh owner approval missing")


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
                "clientMutationId": "phase-c-atomic-427-" + str(comment_id)
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
    # Last authenticated readback with the actual writer credential.
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
    return {"decision": "READBACK_CONFIRMED" if success and confirmed
            else "AMBIGUOUS_STOP_NO_RETRY", "executed": True,
            "retry_allowed": False, "readback_confirmed": confirmed}


def main():
    parser = argparse.ArgumentParser(description="Phase-C2 local one-shot GitHub App worker")
    parser.add_argument("--execute", action="store_true",
                        help="Requires NEW exact owner issue comment; mutates sandbox once")
    parser.add_argument("--approval-comment-id", type=int)
    args = parser.parse_args()
    if not args.execute and args.approval_comment_id is not None:
        parser.error("Approval ID is only valid with --execute")
    try:
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
