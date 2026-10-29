import unittest
from scripts.phase_c_combined_preflight import verify_preflight, TARGET, HEAD, BRANCH, NONCE, OLD_BLOB, NEW_BLOB
from scripts.phase_c_atomic_refs_plan import MUTATION, ZERO_SHA

OLD = ("<?php\ndeclare(strict_types=1);\n// Isolated Phase C exercise ONLY: intentionally unresolved typo.\n"
       "// Never merge this draft PR or route this file into the application.\n"
       "function phase_c_sandbox_example(): bool\n{\n    return flase;\n}\n")
NEW = OLD.replace("return flase;", "return false;")
CANDIDATE = "b" * 40


def fixture():
    def packet(kind, ref):
        return {"query":MUTATION,"variables":{"input":{"repositoryId":"R_kgDOVNLxvg",
                "clientMutationId":"phase-c-"+kind+"-427-123-"+HEAD[:12],
                "refUpdates":[ref]}}}
    a=packet("reserve",{"name":NONCE,"beforeOid":ZERO_SHA,"afterOid":HEAD,"force":False})
    b=packet("advance",{"name":BRANCH,"beforeOid":HEAD,"afterOid":CANDIDATE,"force":False})
    evidence=dict(repository="pecharko/Veiling",repository_node_id="R_kgDOVNLxvg",
        pr_number=426,pr_open=True,pr_draft=True,pr_from_fork=False,
        pr_head=HEAD,sandbox_ref=BRANCH,observed_sandbox_head=HEAD,
        original_blob=OLD_BLOB,old_content=OLD,new_content=NEW,
        candidate_oid=CANDIDATE,candidate_parent=HEAD,candidate_paths=[TARGET],
        candidate_blob=NEW_BLOB,nonce_ref=NONCE,nonce_absent_read=True,
        owner_issue=427,owner_comment_verified=True,app_installation_id=176547610,
        ruleset_tag_active=True,ruleset_branch_active=True,ruleset_bypass_count=0,
        writer_permissions_verified=True)
    return evidence,a,b


class PreflightTests(unittest.TestCase):
    def test_positive_claims_still_never_authorize(self):
        e,a,b=fixture()
        r=verify_preflight(e,a,b)
        self.assertEqual(r["decision"],"REVIEW_ONLY")
        for key in ("write_allowed","network_allowed","retry_allowed","activation_allowed","merge_allowed"):
            self.assertIs(r[key],False)

    def test_security_mismatches_fail_closed(self):
        cases={"pr_head":"a"*40,"pr_draft":False,"pr_from_fork":True,
               "candidate_parent":"c"*40,"candidate_paths":[TARGET,"index.php"],
               "candidate_blob":"d"*40,"original_blob":"c"*40,
               "nonce_absent_read":False,"owner_comment_verified":False,
               "writer_permissions_verified":False,"ruleset_tag_active":False,
               "ruleset_branch_active":False,"ruleset_bypass_count":1,
               "app_installation_id":100,"candidate_oid":"not-a-sha"}
        for field,value in cases.items():
            with self.subTest(field=field):
                e,a,b=fixture()
                e[field]=value
                self.assertEqual(verify_preflight(e,a,b)["decision"],"DENY")

    def test_candidate_oid_and_packet_must_match(self):
        e,a,b=fixture()
        b["variables"]["input"]["refUpdates"][0]["afterOid"]="c"*40
        self.assertEqual(verify_preflight(e,a,b)["decision"],"DENY")

    def test_unexpected_evidence_or_modified_contents_denied(self):
        e,a,b=fixture()
        e["extra"]="unexpected"
        self.assertEqual(verify_preflight(e,a,b)["decision"],"DENY")
        e,a,b=fixture()
        e["new_content"]+="\n"
        self.assertEqual(verify_preflight(e,a,b)["decision"],"DENY")


if __name__=="__main__":
    unittest.main()
