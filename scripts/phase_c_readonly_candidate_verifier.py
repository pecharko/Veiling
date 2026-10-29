"""Phase C candidate verification using injected read-only GitHub API evidence.

This module has NO network client, authentication, token, mutation or write
method. Future callers MUST supply an independently authenticated and scoped
GET transport. All results remain advisory, including successful ones.
"""
import base64
import binascii
import hashlib
import re

from scripts.phase_c_github_evidence_reader import collect_and_check
from scripts.phase_c_atomic_refs_plan import propose_atomic_update_refs

ROOT = "/repos/pecharko/Veiling"
TARGET = "tests/phase_c_sandbox_test.php"
NODE_ID = "R_kgDOVNLxvg"
SHA = re.compile(r"^[0-9a-f]{40}$")


def _sha(value):
    return isinstance(value, str) and SHA.fullmatch(value) is not None


def _decode_blob(blob, expected_sha):
    if not isinstance(blob, dict) or blob.get("sha") != expected_sha:
        raise ValueError("Blob identity mismatch")
    if blob.get("encoding") != "base64" or not isinstance(blob.get("content"), str):
        raise ValueError("Unsupported blob encoding")
    # Max 2048 bytes; fail closed on oversized/malformed/misreported content.
    if type(blob.get("size")) is not int or not 0 <= blob["size"] <= 2048:
        raise ValueError("Invalid blob size")
    encoded = "".join(blob["content"].split())
    content = base64.b64decode(encoded, validate=True)
    if len(content) != blob["size"]:
        raise ValueError("Blob size mismatch")
    git_sha = hashlib.sha1(b"blob " + str(len(content)).encode("ascii") +
                           b"\\0" + content).hexdigest()
    if git_sha != expected_sha:
        raise ValueError("Git blob hash mismatch")
    return content.decode("utf-8")


def _entries(tree, expected_sha):
    if (not isinstance(tree, dict) or tree.get("sha") != expected_sha or
            tree.get("truncated") is not False or
            not isinstance(tree.get("tree"), list) or
            len(tree["tree"]) > 10000):
        raise ValueError("Incomplete or inconsistent Git tree")
    result = {}
    for item in tree["tree"]:
        if not isinstance(item, dict):
            raise ValueError("Invalid tree entry")
        path, kind, mode, oid = (
            item.get("path"), item.get("type"), item.get("mode"), item.get("sha")
        )
        if (not isinstance(path, str) or not path or path.startswith("/") or
                any(part in ("", ".", "..") for part in path.split("/")) or
                kind not in ("tree", "blob", "commit") or
                not isinstance(mode, str) or not _sha(oid) or path in result):
            raise ValueError("Malformed or duplicate tree entry")
        result[path] = (kind, mode, oid)
    return result


def _single_path_diff(old, new):
    if set(old) != set(new):
        raise ValueError("Path added or deleted")
    changed = []
    for path in old:
        before, after = old[path], new[path]
        if before[:2] != after[:2]:
            raise ValueError("Type or mode changed")
        if before[2] != after[2]:
            if before[0] == "tree" and TARGET.startswith(path + "/"):
                continue  # The containing directory naturally changes OID.
            if path != TARGET or before[0] != "blob" or before[1] != "100644":
                raise ValueError("Other file or tree changed")
            changed.append(path)
    if changed != [TARGET]:
        raise ValueError("Not exactly one changed blob")
    return old[TARGET][2], new[TARGET][2]


def verify_candidate_readonly(api_get, pr_number, issue_number, comment_id,
                              auth, candidate_sha):
    """Return a non-executable atomic updateRefs review plan or DENY.

    This does not prove credential provenance: api_get is injected by caller.
    The eventual trusted worker must authenticate GitHub independently.
    """
    deny = {"decision": "DENY", "write_allowed": False,
            "merge_allowed": False, "dispatch_allowed": False,
            "production_authorized": False}
    if not callable(api_get) or not isinstance(auth, dict) or not _sha(candidate_sha):
        return dict(deny, reason="Invalid reader, approval or candidate SHA")
    try:
        owner = collect_and_check(api_get, pr_number, issue_number,
                                  comment_id, auth)
        if owner.get("verified") is not True:
            raise ValueError("Owner evidence denied")
        original_sha = owner["head_sha"]
        if candidate_sha == original_sha:
            raise ValueError("Candidate equals approved branch head")
        repo = api_get(ROOT)
        if (not isinstance(repo, dict) or repo.get("full_name") != "pecharko/Veiling"
                or repo.get("node_id") != NODE_ID or repo.get("archived") is True):
            raise ValueError("Wrong repository metadata")

        old_commit = api_get(ROOT + "/git/commits/" + original_sha)
        new_commit = api_get(ROOT + "/git/commits/" + candidate_sha)
        if not isinstance(old_commit, dict) or not isinstance(new_commit, dict):
            raise ValueError("Invalid commit data")
        if old_commit.get("sha") != original_sha or new_commit.get("sha") != candidate_sha:
            raise ValueError("Commit hash mismatch")
        parents = new_commit.get("parents")
        if (not isinstance(parents, list) or len(parents) != 1 or
                not isinstance(parents[0], dict) or parents[0].get("sha") != original_sha):
            raise ValueError("Candidate must have exact approved parent")
        old_tree_sha = old_commit.get("tree", {}).get("sha")
        new_tree_sha = new_commit.get("tree", {}).get("sha")
        if not _sha(old_tree_sha) or not _sha(new_tree_sha):
            raise ValueError("Invalid tree references")
        old_entries = _entries(
            api_get(ROOT + "/git/trees/" + old_tree_sha + "?recursive=1"), old_tree_sha
        )
        new_entries = _entries(
            api_get(ROOT + "/git/trees/" + new_tree_sha + "?recursive=1"), new_tree_sha
        )
        old_blob_sha, new_blob_sha = _single_path_diff(old_entries, new_entries)
        if old_blob_sha != owner["blob_sha"]:
            raise ValueError("Owner-approved blob not in tree")
        before = _decode_blob(api_get(ROOT + "/git/blobs/" + old_blob_sha),
                              old_blob_sha)
        after = _decode_blob(api_get(ROOT + "/git/blobs/" + new_blob_sha),
                             new_blob_sha)
        expected = hashlib.sha256(after.encode("utf-8")).hexdigest()
        if (before.count("return flase;") != 1 or not before.startswith("<?php") or
                after != before.replace("return flase;", "return false;", 1) or
                auth.get("new_sha256") != expected):
            raise ValueError("Unexpected correction or result digest")
        evidence = {
            "repo": "pecharko/Veiling",
            "repository_node_id": NODE_ID,
            "owner_verified": True, "pr_open": True, "from_fork": False,
            "pr_number": pr_number, "issue_id": auth["issue_id"],
            "comment_id": auth["comment_id"],
            "branch": owner["branch"], "target_path": owner["path"],
            "head_sha": original_sha, "observed_head_sha": original_sha,
            "blob_sha": old_blob_sha, "observed_blob_sha": old_blob_sha,
            "proposed_commit_sha": candidate_sha,
            "proposed_parent_sha": original_sha,
            "proposed_changed_paths": [TARGET], "proposed_commit_verified": True,
            "old_content": before, "new_content": after,
            "approved_new_sha256": expected,
        }
        plan = propose_atomic_update_refs(evidence)
        if plan.get("decision") != "REVIEW_ONLY":
            raise ValueError("Atomic plan denied")
        # Even full evidence with a validated candidate does not prove a
        # durable reservation or authorize issuing a GraphQL mutation.
        return dict(plan, write_allowed=False, merge_allowed=False,
                    dispatch_allowed=False, production_authorized=False,
                    origin="Injected read-only GitHub API; no credential provenance")
    except (ValueError, KeyError, TypeError, AttributeError, OSError,
            binascii.Error, UnicodeError):
        return dict(deny, reason="Authenticated evidence and candidate validation failed")
