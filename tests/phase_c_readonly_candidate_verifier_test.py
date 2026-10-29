"""Offline adversarial tests for read-only GitHub candidate verification."""
import base64
import copy
import hashlib
import unittest

from scripts.phase_c_readonly_candidate_verifier import verify_candidate_readonly

ROOT = "/repos/pecharko/Veiling"
HEAD, CANDIDATE = "a" * 40, "c" * 40
OLD_TREE, NEW_TREE = "1" * 40, "2" * 40
TARGET = "tests/phase_c_sandbox_test.php"
BEFORE = "<?php function phase_c_sandbox() { return flase; }"
AFTER = BEFORE.replace("return flase;", "return false;", 1)
ISSUE_URL = "https://api.github.com" + ROOT + "/issues/900"


def blob(content):
    data = content.encode("utf-8")
    digest = hashlib.sha1(
        b"blob " + str(len(data)).encode("ascii") + b"\0" + data
    ).hexdigest()
    return digest, {
        "sha": digest, "encoding": "base64", "size": len(data),
        "content": base64.b64encode(data).decode("ascii"),
    }


OLD_BLOB, OLD_RESPONSE = blob(BEFORE)
NEW_BLOB, NEW_RESPONSE = blob(AFTER)


def fixture():
    responses = {
        ROOT + "/pulls/901": {
            "number": 901, "state": "open", "merged": False,
            "base": {"repo": {"full_name": "pecharko/Veiling"}},
            "head": {"repo": {"full_name": "pecharko/Veiling"},
                     "ref": "test/phase-c-sandbox-20261029", "sha": HEAD},
        },
        ROOT + "/issues/900": {
            "id": 100, "state": "open", "url": ISSUE_URL,
            "repository_url": "https://api.github.com" + ROOT,
            "user": {"id": 343879284},
        },
        ROOT + "/issues/comments/101": {
            "id": 101, "issue_url": ISSUE_URL, "user": {"id": 343879284},
            "body": "PHASE-C-SANDBOX-ONCE pr=901 head=" +
                    HEAD + " blob=" + OLD_BLOB,
        },
        ROOT + "/contents/" + TARGET + "?ref=" + HEAD: {
            "sha": OLD_BLOB, "path": TARGET, "type": "file",
        },
        ROOT: {
            "full_name": "pecharko/Veiling",
            "node_id": "R_kgDOVNLxvg", "archived": False,
            "default_branch": "main",
        },
        ROOT + "/git/commits/" + HEAD: {
            "sha": HEAD, "parents": [{"sha": "d" * 40}],
            "tree": {"sha": OLD_TREE},
        },
        ROOT + "/git/commits/" + CANDIDATE: {
            "sha": CANDIDATE, "parents": [{"sha": HEAD}],
            "tree": {"sha": NEW_TREE},
        },
        ROOT + "/git/trees/" + OLD_TREE + "?recursive=1": {
            "sha": OLD_TREE, "truncated": False, "tree": [
                {"path": "tests", "type": "tree", "mode": "040000",
                 "sha": "3" * 40},
                {"path": TARGET, "type": "blob", "mode": "100644",
                 "sha": OLD_BLOB},
                {"path": "README.md", "type": "blob", "mode": "100644",
                 "sha": "e" * 40},
            ],
        },
        ROOT + "/git/trees/" + NEW_TREE + "?recursive=1": {
            "sha": NEW_TREE, "truncated": False, "tree": [
                {"path": "tests", "type": "tree", "mode": "040000",
                 "sha": "4" * 40},
                {"path": TARGET, "type": "blob", "mode": "100644",
                 "sha": NEW_BLOB},
                {"path": "README.md", "type": "blob", "mode": "100644",
                 "sha": "e" * 40},
            ],
        },
        ROOT + "/git/blobs/" + OLD_BLOB: copy.deepcopy(OLD_RESPONSE),
        ROOT + "/git/blobs/" + NEW_BLOB: copy.deepcopy(NEW_RESPONSE),
    }
    auth = {
        "issue_id": 100, "comment_id": 101, "used": False, "attempt": 0,
        "new_sha256": hashlib.sha256(AFTER.encode("utf-8")).hexdigest(),
    }
    return responses, auth


def verdict(responses, auth, candidate=CANDIDATE):
    return verify_candidate_readonly(
        responses.__getitem__, 901, 900, 101, auth, candidate
    )


class ReadonlyCandidateTests(unittest.TestCase):
    def test_authentic_shape_produces_only_inert_plan(self):
        responses, auth = fixture()
        result = verdict(responses, auth)
        self.assertEqual(result["decision"], "REVIEW_ONLY")
        for flag in ("write_allowed", "merge_allowed", "dispatch_allowed",
                     "production_authorized"):
            self.assertIs(result[flag], False)
        reserve = result["reservation"]["variables"]["input"]["refUpdates"]
        advance = result["branch_update"]["variables"]["input"]["refUpdates"]
        self.assertEqual(len(reserve), 1)
        self.assertEqual(len(advance), 1)
        self.assertEqual(reserve[0]["name"], "refs/tags/phase-c-once-issue-100")
        self.assertEqual(reserve[0]["beforeOid"], "0" * 40)
        self.assertEqual(reserve[0]["afterOid"], HEAD)
        self.assertEqual(advance[0]["name"],
                         "refs/heads/test/phase-c-sandbox-20261029")
        self.assertEqual(advance[0]["beforeOid"], HEAD)
        self.assertEqual(advance[0]["afterOid"], CANDIDATE)
        self.assertTrue(result["reservation_before_branch"])

    def test_owner_and_parent_guard(self):
        for bad in ("owner", "parent", "multi-parent", "node", "archive", "default", "digest"):
            with self.subTest(bad=bad):
                r, a = fixture()
                if bad == "owner":
                    r[ROOT + "/issues/comments/101"]["user"]["id"] = 999
                elif bad == "parent":
                    r[ROOT + "/git/commits/" + CANDIDATE]["parents"] = [
                        {"sha": "f" * 40}]
                elif bad == "multi-parent":
                    r[ROOT + "/git/commits/" + CANDIDATE]["parents"].append(
                        {"sha": "d" * 40})
                elif bad == "node":
                    r[ROOT]["node_id"] = "R_kgDODifferent"
                elif bad == "archive":
                    r[ROOT]["archived"] = True
                elif bad == "default":
                    r[ROOT]["default_branch"] = "feature/unsafe"
                elif bad == "digest":
                    a["new_sha256"] = "f" * 64
                self.assertEqual(verdict(r, a)["decision"], "DENY")

    def test_tree_path_and_mode_attacks(self):
        for bad in ("extra-file", "other-file-change", "truncated",
                    "duplicate", "symlink", "wrong-target-blob"):
            with self.subTest(bad=bad):
                r, a = fixture()
                tree = r[ROOT + "/git/trees/" + NEW_TREE + "?recursive=1"]
                if bad == "extra-file":
                    tree["tree"].append({"path": "private/config.php", "type": "blob",
                                         "mode": "100644", "sha": "f" * 40})
                elif bad == "other-file-change":
                    tree["tree"][2]["sha"] = "f" * 40
                elif bad == "truncated":
                    tree["truncated"] = True
                elif bad == "duplicate":
                    tree["tree"].append(copy.deepcopy(tree["tree"][1]))
                elif bad == "symlink":
                    tree["tree"][1]["mode"] = "120000"
                elif bad == "wrong-target-blob":
                    tree["tree"][1]["sha"] = OLD_BLOB
                self.assertEqual(verdict(r, a)["decision"], "DENY")

    def test_blob_integrity_and_transport_failure(self):
        for bad in ("bad-base64", "wrong-size", "blob-forgery", "missing-api"):
            with self.subTest(bad=bad):
                r, a = fixture()
                blob_response = r[ROOT + "/git/blobs/" + NEW_BLOB]
                if bad == "bad-base64":
                    blob_response["content"] = "?not base64?"
                elif bad == "wrong-size":
                    blob_response["size"] += 1
                elif bad == "blob-forgery":
                    blob_response["content"] = base64.b64encode(
                        b"<?php return true;").decode("ascii")
                    blob_response["size"] = len(b"<?php return true;")
                elif bad == "missing-api":
                    del r[ROOT + "/git/blobs/" + OLD_BLOB]
                self.assertEqual(verdict(r, a)["decision"], "DENY")

    def test_injected_reader_does_not_grant_authenticity_or_actions(self):
        result = verify_candidate_readonly(None, 901, 900, 101, {}, CANDIDATE)
        self.assertEqual(result["decision"], "DENY")
        self.assertIs(result["write_allowed"], False)

        def failing_get(_path):
            raise OSError("read-only API unavailable")
        result = verify_candidate_readonly(
            failing_get, 901, 900, 101, {}, CANDIDATE
        )
        self.assertEqual(result["decision"], "DENY")


if __name__ == "__main__":
    unittest.main()
