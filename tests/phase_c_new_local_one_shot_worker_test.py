"""Offline-only adversarial tests for PR475 fixed-scope one-shot worker."""
import base64
import copy
import hashlib
import inspect
import unittest
from unittest.mock import patch

from scripts import phase_c_new_local_one_shot_worker as worker


def installed():
    return {
        "id": worker.INSTALLATION, "app_id": worker.APP_ID,
        "app_slug": worker.SLUG, "repository_selection": "selected",
        "permissions": dict(worker.EXPECTED_PERMISSIONS),
        "target_type": "User", "target_id": worker.OWNER_ID,
        "account": {"id": worker.OWNER_ID, "login": "pecharko"},
        "suspended_at": None,
    }


class PhaseCNewWorkerTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        worker.configure_issue(9013)

    def test_fixed_scope_and_inert_approval_phrase(self):
        self.assertEqual(worker.HEAD, "2ee5419b4642b2108414c8b9d4f193298004b23a")
        self.assertEqual(worker.CANDIDATE, "73be8a820e0801c9ceba3b548da91a5732325ec4")
        self.assertEqual(worker.TARGET, "tests/phase_c_new_repair_test.php")
        self.assertEqual(worker.NONCE, "refs/tags/phase-c-once-issue-9013")
        phrase = worker.approval_phrase("b"*40)
        for v in ("pr=475", "issue=9013", worker.HEAD, worker.CANDIDATE,
                  worker.TARGET, worker.ORIGINAL_BLOB, "worker="+"b"*40):
            self.assertIn(v, phrase)
        with self.assertRaises(worker.Refused):
            worker.configure_issue(427)
        with self.assertRaises(worker.Refused):
            worker.configure_issue(9014)

    def test_installation_identity_fails_closed(self):
        app = {"id": worker.APP_ID, "slug": worker.SLUG}
        valid = installed()
        calls = []
        def get_mock(_tok, path):
            calls.append(path)
            return [app, valid, valid][len(calls)-1]
        with patch.object(worker, "get", side_effect=get_mock):
            worker.verify_app("jwt")
        self.assertEqual(calls, ["/app", "/app/installations/176547610",
                                 worker.BASE+"/installation"])
        for key, wrong in (("repository_selection", "all"),
                           ("permissions", {"contents": "write", "issues": "write"}),
                           ("id", 9), ("target_id", 8),
                           ("suspended_at", "non-null")):
            with self.subTest(key=key):
                bad = dict(valid, **{key: wrong})
                with patch.object(worker, "get", side_effect=[app, bad]):
                    with self.assertRaises(worker.Refused):
                        worker.verify_app("jwt")

    def test_exact_rules_for_both_branches_and_nonce(self):
        good_branch = {
            "enforcement": "active", "source": worker.REPO,
            "target": "branch", "bypass_actors": [],
            "current_user_can_bypass": "never",
            "conditions": {"ref_name": {
                "include": worker.BRANCH_RULE_INCLUDES, "exclude": []}},
            "rules": [{"type": "deletion"}, {"type": "non_fast_forward"}],
        }
        good_tag = copy.deepcopy(good_branch)
        good_tag.update(target="tag", conditions={"ref_name":{
            "include": ["refs/tags/phase-c-once-issue-*"], "exclude": []}},
            rules=[{"type": "update"}, {"type": "deletion"}])
        with patch.object(worker, "get", side_effect=[good_branch,good_tag]):
            worker.verify_rules("writer")
        for key, wrong in (("enforcement", "disabled"),
                           ("bypass_actors", [{"actor_id": 1}]),
                           ("current_user_can_bypass", "always"),
                           ("rules", [{"type": "deletion"}])):
            with self.subTest(key=key):
                bad = dict(good_branch, **{key: wrong})
                with patch.object(worker,"get",return_value=bad):
                    with self.assertRaises(worker.Refused):
                        worker.verify_rules("writer")

    def test_absent_nonce_required_before_write(self):
        head = {"ref": worker.BRANCH, "object": {"sha": worker.HEAD}}
        for code in (200, 403, 409, 500):
            with self.subTest(code=code), patch.object(worker, "get",
                    return_value=head), patch.object(worker,"_api",return_value=(code, {})):
                with self.assertRaises(worker.Refused):
                    worker.verify_ref_states("reader")
        with patch.object(worker,"get",return_value=head),patch.object(
                worker, "_api", return_value=(404, {})):
            worker.verify_ref_states("reader")

    def test_fresh_owner_comment_requires_authentication_and_scope(self):
        issue = {"number": worker.ISSUE, "state": "open",
                 "created_at": "2026-10-31T06:00:00Z",
                 "user": {"login": "pecharko", "id": worker.OWNER_ID}}
        comment = {
            "id": 600, "created_at": "2026-10-31T06:05:00Z",
            "user": {"login": "pecharko", "id": worker.OWNER_ID},
            "body": worker.approval_phrase("b"*40),
            "issue_url": "https://api.github.com"+worker.BASE+"/issues/9013",
        }
        with patch.object(worker, "get", side_effect=[issue, comment]):
            worker.verify_fresh_approval("read", 600, "b"*40)
        for key, bad in (
            ("body", "go"), ("issue_url", "https://api.github.com/another"),
            ("user", {"login":"pecharko","id":999}),
            ("created_at", "2026-10-30T00:00:00Z"),
        ):
            with self.subTest(key=key):
                wrong = dict(comment, **{key: bad})
                with patch.object(worker,"get",side_effect=[issue,wrong]):
                    with self.assertRaises(worker.Refused):
                        worker.verify_fresh_approval("read", 600, "b"*40)

    def test_default_readonly_never_mints_or_posts(self):
        with patch.object(worker, "verify_app"), \
             patch.object(worker, "verify_static_evidence"), \
             patch.object(worker, "mint_exact_writer_token") as mint, \
             patch.object(worker, "_api") as http:
            out=worker.run("fake-app", "fake-owner")
        self.assertEqual(out["decision"], "READ_ONLY_VERIFIED")
        self.assertFalse(out["executed"])
        mint.assert_not_called()
        http.assert_not_called()

    def test_missing_owner_approval_blocks_token_mint(self):
        with patch.object(worker, "verify_app"), \
             patch.object(worker, "verify_static_evidence"), \
             patch.object(worker, "verify_checkout", return_value="b"*40), \
             patch.object(worker, "verify_fresh_approval",
                          side_effect=worker.Refused("not approved")), \
             patch.object(worker, "mint_exact_writer_token") as mint:
            with self.assertRaises(worker.Refused):
                worker.run("jwt", "owner",execute=True,comment_id=600)
            mint.assert_not_called()

    def test_actual_writer_ruleset_error_blocks_mutation(self):
        with patch.object(worker,"verify_app"), \
             patch.object(worker,"verify_static_evidence"), \
             patch.object(worker,"verify_checkout",return_value="b"*40), \
             patch.object(worker,"verify_fresh_approval"), \
             patch.object(worker,"get",return_value={"object":{"sha":"b"*40}}), \
             patch.object(worker,"mint_exact_writer_token",return_value="writer"), \
             patch.object(worker,"verify_rules",side_effect=worker.Refused("no scope")), \
             patch.object(worker,"_api") as request:
            with self.assertRaises(worker.Refused):
                worker.run("jwt","owner",execute=True,comment_id=600)
            request.assert_not_called()

    def test_mutation_envelope_only_two_fixed_nonforce_refs(self):
        m=worker.mutation(600)["variables"]["input"]
        self.assertEqual(m["repositoryId"], worker.REPO_NODE)
        self.assertEqual(m["refUpdates"], [
            {"name":worker.NONCE,"beforeOid":"0"*40,
             "afterOid":worker.HEAD,"force":False},
            {"name":worker.BRANCH,"beforeOid":worker.HEAD,
             "afterOid":worker.CANDIDATE,"force":False},
        ])
        self.assertNotIn("refs/heads/main",str(m))

    def test_exactly_one_mutation_and_independent_readback(self):
        calls=[]
        def fake_api(_token,path,method="GET",payload=None):
            calls.append((path,method))
            if path=="/graphql":
                return 200, {"data":{"updateRefs":{
                    "clientMutationId":payload["variables"]["input"]["clientMutationId"]}}}
            if "/heads/" in path:
                return 200, {"object":{"sha":worker.CANDIDATE}}
            return 200, {"object":{"sha":worker.HEAD}}
        with patch.object(worker,"verify_app"), \
             patch.object(worker,"verify_static_evidence"), \
             patch.object(worker,"verify_checkout",return_value="b"*40), \
             patch.object(worker,"verify_fresh_approval"), \
             patch.object(worker,"get",return_value={"object":{"sha":"b"*40}}), \
             patch.object(worker,"mint_exact_writer_token",return_value="writer"), \
             patch.object(worker,"verify_rules"), \
             patch.object(worker,"verify_ref_states"), \
             patch.object(worker,"verify_full_readback",return_value=True), \
             patch.object(worker,"_api",side_effect=fake_api):
            out=worker.run("jwt","owner",execute=True,comment_id=600)
        self.assertEqual(out["decision"],"READBACK_CONFIRMED")
        self.assertEqual(calls.count(("/graphql","POST")),1)
        self.assertEqual(len(calls),3)

    def test_ambiguous_post_does_not_retry(self):
        calls=[]
        def fake_api(_token,path,method="GET",payload=None):
            calls.append((path,method))
            if path=="/graphql":
                raise TimeoutError("lost reply after POST")
            return 404, {}
        with patch.object(worker,"verify_app"), \
             patch.object(worker,"verify_static_evidence"), \
             patch.object(worker,"verify_checkout",return_value="b"*40), \
             patch.object(worker,"verify_fresh_approval"), \
             patch.object(worker,"get",return_value={"object":{"sha":"b"*40}}), \
             patch.object(worker,"mint_exact_writer_token",return_value="writer"), \
             patch.object(worker,"verify_rules"), \
             patch.object(worker,"verify_ref_states"), \
             patch.object(worker,"_api",side_effect=fake_api):
            out=worker.run("jwt","owner",execute=True,comment_id=600)
        self.assertEqual(out["decision"],"AMBIGUOUS_STOP_NO_RETRY")
        self.assertFalse(out["retry_allowed"])
        self.assertEqual(calls.count(("/graphql","POST")),1)

    def test_unverified_postwrite_digest_refused(self):
        with patch.object(worker,"get",return_value={"state":"closed"}):
            with self.assertRaises(worker.Refused):
                worker.verify_full_readback("owner")

    def test_raw_transport_rejects_extra_posts_before_opening_connection(self):
        with patch.object(worker.http.client, "HTTPSConnection") as conn:
            for path, payload in (
                ("/repos/pecharko/Veiling/issues", {"title": "unexpected"}),
                ("/graphql", {"query": "mutation { deleteRepository }"}),
                ("/graphql", {"variables": {"input": {"clientMutationId":
                    "phase-c-new-9013-600-" + worker.HEAD[:12],
                    "refUpdates": [{"name": "refs/heads/main"}]}}}),
                ("/app/installations/" + str(worker.INSTALLATION) + "/access_tokens",
                 {"repositories": ["Veiling"], "permissions": {"contents": "write",
                                                             "issues": "write"}}),
            ):
                with self.subTest(path=path):
                    with self.assertRaises(worker.Refused):
                        worker._api("fake-token-long", path, "POST", payload)
            conn.assert_not_called()

    def test_source_never_targets_production(self):
        src=inspect.getsource(worker)
        self.assertNotIn("41lokeren.be",src)
        self.assertNotIn("deploy_ovh",src)
        self.assertNotIn("workflow_dispatch",src)
        self.assertEqual(worker.REPO,"pecharko/Veiling")


if __name__=="__main__":
    unittest.main()
