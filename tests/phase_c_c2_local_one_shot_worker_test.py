"""No-network adversarial tests for the dormant local Phase C2 GitHub App worker."""
import unittest
import base64
import copy
from unittest.mock import patch
from scripts import phase_c_c2_local_one_shot_worker as worker


class LocalWorkerTests(unittest.TestCase):
    def test_atomic_scope_is_exclusively_nonce_and_sandbox(self):
        payload = worker.mutation(6493461155)
        x = payload["variables"]["input"]
        self.assertEqual(x["repositoryId"], worker.REPO_NODE)
        self.assertEqual(len(x["refUpdates"]), 2)
        self.assertEqual(x["refUpdates"][0], {
            "name": worker.NONCE, "beforeOid": "0" * 40,
            "afterOid": worker.HEAD, "force": False})
        self.assertEqual(x["refUpdates"][1], {
            "name": worker.BRANCH, "beforeOid": worker.HEAD,
            "afterOid": worker.CANDIDATE, "force": False})

    def test_no_credentials_or_mutation_required_for_probe(self):
        with patch.object(worker, "verify_app"), \
             patch.object(worker, "verify_static_evidence"), \
             patch.object(worker, "mint_exact_writer_token") as mint, \
             patch.object(worker, "_api") as request:
            result = worker.run("dummy-app-jwt", "dummy-owner-token")
        self.assertEqual(result["decision"], "READ_ONLY_VERIFIED")
        mint.assert_not_called()
        request.assert_not_called()

    def test_authenticated_app_and_installation_fail_closed(self):
        app = {"id": worker.APP_ID, "slug": worker.SLUG}
        installation = {
            "id": worker.INSTALLATION, "app_id": worker.APP_ID,
            "app_slug": worker.SLUG, "repository_selection": "selected",
            "permissions": dict(worker.EXPECTED_PERMISSIONS),
            "target_type": "User", "target_id": worker.OWNER_ID,
            "account": {"id": worker.OWNER_ID, "login": "pecharko"},
            "suspended_at": None}
        expected_paths = ["/app", "/app/installations/" + str(worker.INSTALLATION),
                          worker.BASE + "/installation"]
        calls = []
        def authenticated_get(token, path):
            calls.append(path)
            return [app, installation, installation][len(calls) - 1]
        with patch.object(worker, "get", side_effect=authenticated_get):
            worker.verify_app("jwt")
        self.assertEqual(calls, expected_paths)
        for key, wrong in (("id", 172331568), ("repository_selection", "all"),
                           ("target_id", 888), ("suspended_at", "2026-10-29T00:00:00Z"),
                           ("permissions", {"contents": "write", "issues": "write"})):
            incorrect = dict(installation, **{key: wrong})
            with self.subTest(key=key, source="installation"), patch.object(
                    worker, "get", side_effect=[app, incorrect]):
                with self.assertRaises(worker.Refused):
                    worker.verify_app("jwt")
            with self.subTest(key=key, source="repository"), patch.object(
                    worker, "get", side_effect=[app, installation, incorrect]):
                with self.assertRaises(worker.Refused):
                    worker.verify_app("jwt")
        for bad_account in ({"id": worker.OWNER_ID, "login": "another"},
                            {"id": 12, "login": "pecharko"}, None):
            with self.subTest(account=bad_account), patch.object(
                    worker, "get", side_effect=[
                        app, installation, dict(installation, account=bad_account)]):
                with self.assertRaises(worker.Refused):
                    worker.verify_app("jwt")
        with patch.object(worker, "get", return_value={
                "id": 123, "slug": worker.SLUG}):
            with self.assertRaises(worker.Refused):
                worker.verify_app("jwt")

    def test_nonce_absence_must_be_authenticated_404(self):
        head = {"ref": worker.BRANCH, "object": {"sha": worker.HEAD}}
        for status in (200, 401, 403, 500):
            with self.subTest(status=status), patch.object(worker, "get",
                 return_value=head), patch.object(worker, "_api",
                 return_value=(status, {})):
                with self.assertRaises(worker.Refused):
                    worker.verify_ref_states("writer")
        with patch.object(worker, "get", return_value=head), \
             patch.object(worker, "_api", return_value=(404, {})):
            worker.verify_ref_states("writer")

    def test_fresh_owner_authorization_is_exact_and_rev_bound(self):
        comment_id = worker.OLD_COMMENT + 123
        rev = "a" * 40
        data = {"user": {"login": "pecharko", "id": worker.OWNER_ID},
                "issue_url": "https://api.github.com/repos/pecharko/Veiling/issues/427",
                "body": worker.approval_phrase(rev)}
        with patch.object(worker, "get", return_value=data):
            worker.verify_fresh_approval("owner", comment_id, rev)
        for variant in (dict(data, body="go"), dict(data, body=worker.approval_phrase("b" * 40)),
                        dict(data, user={"login": "pecharko", "id": 999}),
                        dict(data, issue_url="https://api.github.com/repos/x/y/issues/427")):
            with patch.object(worker, "get", return_value=variant):
                with self.assertRaises(worker.Refused):
                    worker.verify_fresh_approval("owner", comment_id, rev)
        with self.assertRaises(worker.Refused):
            worker.verify_fresh_approval("owner", worker.OLD_COMMENT, rev)

    def test_no_post_without_exact_fresh_approval(self):
        with patch.object(worker, "verify_app"), \
             patch.object(worker, "verify_static_evidence"), \
             patch.object(worker, "verify_checkout", return_value="a" * 40), \
             patch.object(worker, "verify_fresh_approval", side_effect=worker.Refused("no")), \
             patch.object(worker, "mint_exact_writer_token") as mint:
            with self.assertRaises(worker.Refused):
                worker.run("jwt", "owner", execute=True, comment_id=worker.OLD_COMMENT + 1)
            mint.assert_not_called()

    def test_authenticated_evidence_binds_exact_tree_blob_and_rules(self):
        original = (b"<?php\ndeclare(strict_types=1);\n"
                    b"// Isolated Phase C exercise ONLY: intentionally unresolved typo.\n"
                    b"// Never merge this draft PR or route this file into the application.\n"
                    b"function phase_c_sandbox_example(): bool\n"
                    b"{\n    return flase;\n}\n")
        fixed = original.replace(b"return flase;", b"return false;")
        owner = {"login": "pecharko", "id": worker.OWNER_ID}
        issue_body = ("PHASE-C-SANDBOX-ONCE pr=426 head=" + worker.HEAD
                      + " blob=" + worker.ORIGINAL_BLOB)
        repo_info = {"full_name": worker.REPO, "node_id": worker.REPO_NODE}
        paths = {
            worker.BASE: repo_info,
            worker.BASE + "/pulls/426": {
                "state": "open", "draft": True,
                "head": {"sha": worker.HEAD, "repo": repo_info},
                "base": {"repo": repo_info}},
            worker.BASE + "/issues/427": {"user": owner, "state": "open", "body": issue_body},
            worker.BASE + "/issues/comments/" + str(worker.OLD_COMMENT):
                {"user": owner, "body": issue_body},
            worker.BASE + "/rulesets/26113993": {
                "enforcement": "active", "target": "branch", "source": worker.REPO,
                "bypass_actors": [], "conditions": {"ref_name": {
                    "include": [worker.BRANCH], "exclude": []}},
                "rules": [{"type": "deletion"}, {"type": "non_fast_forward"}]},
            worker.BASE + "/rulesets/26113833": {
                "enforcement": "active", "target": "tag", "source": worker.REPO,
                "bypass_actors": [], "conditions": {"ref_name": {
                    "include": ["refs/tags/phase-c-once-issue-*"], "exclude": []}},
                "rules": [{"type": "update"}, {"type": "deletion"}]},
            worker.BASE + "/compare/" + worker.HEAD + "..." + worker.CANDIDATE: {
                "status": "ahead", "ahead_by": 1, "behind_by": 0,
                "files": [{"filename": worker.TARGET, "status": "modified",
                           "sha": worker.NEW_BLOB, "additions": 1, "deletions": 1}]},
            worker.BASE + "/git/commits/" + worker.CANDIDATE: {
                "parents": [{"sha": worker.HEAD}]},
            worker.BASE + "/git/blobs/" + worker.ORIGINAL_BLOB: {
                "sha": worker.ORIGINAL_BLOB, "encoding": "base64",
                "content": base64.b64encode(original).decode()},
            worker.BASE + "/git/blobs/" + worker.NEW_BLOB: {
                "sha": worker.NEW_BLOB, "encoding": "base64",
                "content": base64.b64encode(fixed).decode()},
            worker.BASE + "/git/ref/" + worker.BRANCH.removeprefix("refs/"): {
                "ref": worker.BRANCH, "object": {"sha": worker.HEAD}},
        }

        def check(data):
            with patch.object(worker, "get", side_effect=lambda token, path: data[path]), \
                 patch.object(worker, "_api", return_value=(404, {})):
                worker.verify_static_evidence("owner-read-token")

        check(paths)
        for key, edit in (
            (worker.BASE + "/compare/" + worker.HEAD + "..." + worker.CANDIDATE,
             lambda x: x["files"][0].update(sha="f" * 40)),
            (worker.BASE + "/rulesets/26113993",
             lambda x: x["conditions"]["ref_name"].update(exclude=[worker.BRANCH])),
            (worker.BASE + "/rulesets/26113833",
             lambda x: x.update(bypass_actors=[{"actor_id": 1}])),
            (worker.BASE + "/issues/427",
             lambda x: x["user"].update(id=999)),
        ):
            with self.subTest(key=key):
                bad = copy.deepcopy(paths)
                edit(bad[key])
                with self.assertRaises(worker.Refused):
                    check(bad)

    def test_main_drift_prevents_writer_token_mint(self):
        with patch.object(worker, "verify_app"), \
             patch.object(worker, "verify_static_evidence"), \
             patch.object(worker, "verify_checkout", return_value="a" * 40), \
             patch.object(worker, "verify_fresh_approval"), \
             patch.object(worker, "get", return_value={"object": {"sha": "b" * 40}}), \
             patch.object(worker, "mint_exact_writer_token") as mint:
            with self.assertRaises(worker.Refused):
                worker.run("jwt", "owner", execute=True, comment_id=worker.OLD_COMMENT + 1)
            mint.assert_not_called()

    def test_one_mutation_call_and_independent_readback(self):
        calls = []
        def transport(token, path, method="GET", payload=None):
            calls.append((path, method))
            if path == "/graphql":
                return 200, {"data": {"updateRefs": {
                    "clientMutationId": payload["variables"]["input"]["clientMutationId"]}}}
            if "/heads/" in path:
                return 200, {"object": {"sha": worker.CANDIDATE}}
            return 200, {"object": {"sha": worker.HEAD}}
        with patch.object(worker, "verify_app"), \
             patch.object(worker, "verify_static_evidence"), \
             patch.object(worker, "verify_checkout", return_value="a" * 40), \
             patch.object(worker, "verify_fresh_approval"), \
             patch.object(worker, "get", return_value={"object": {"sha": "a" * 40}}), \
             patch.object(worker, "mint_exact_writer_token", return_value="minted"), \
             patch.object(worker, "verify_ref_states"), \
             patch.object(worker, "_api", side_effect=transport):
            result = worker.run("jwt", "owner", execute=True, comment_id=worker.OLD_COMMENT + 1)
        self.assertEqual(result["decision"], "READBACK_CONFIRMED")
        self.assertEqual(len([x for x in calls if x == ("/graphql", "POST")]), 1)
        self.assertEqual(len(calls), 3)

    def test_ambiguous_network_error_never_retries(self):
        calls = []
        def transport(token, path, method="GET", payload=None):
            calls.append((path, method))
            if path == "/graphql":
                raise TimeoutError("response lost after write")
            return 404, {}
        with patch.object(worker, "verify_app"), \
             patch.object(worker, "verify_static_evidence"), \
             patch.object(worker, "verify_checkout", return_value="a" * 40), \
             patch.object(worker, "verify_fresh_approval"), \
             patch.object(worker, "get", return_value={"object": {"sha": "a" * 40}}), \
             patch.object(worker, "mint_exact_writer_token", return_value="minted"), \
             patch.object(worker, "verify_ref_states"), \
             patch.object(worker, "_api", side_effect=transport):
            result = worker.run("jwt", "owner", execute=True, comment_id=worker.OLD_COMMENT + 1)
        self.assertEqual(result["decision"], "AMBIGUOUS_STOP_NO_RETRY")
        self.assertFalse(result["retry_allowed"])
        self.assertEqual(len([x for x in calls if x == ("/graphql", "POST")]), 1)
        self.assertEqual(len(calls), 3)


if __name__ == "__main__":
    unittest.main()
