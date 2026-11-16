"""Offline security tests for the DORMANT artist overview snapshot release adapter.

No OVH access, workflow dispatch, database write, snapshot restore or production operation.
"""
import json
import os
from pathlib import Path
import sys
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch

from scripts import deploy_artist_overview_snapshot as adapter


class ArtistOverviewSnapshotReleaseTests(unittest.TestCase):
    def fixture(self, root):
        source = root / adapter.TARGET
        source.parent.mkdir(parents=True)
        source.write_bytes(
            b"<?php // 41lokeren-v8-catalog-only\n"
            b"const SNAP_INSERT = ['settings'=>['show_report_issue_link','show_artists_overview','updated_at']];\n"
        )
        manifest = root / adapter.MANIFEST_NAME
        manifest.parent.mkdir(parents=True)
        manifest.write_text(json.dumps({"schema": 1, "files": [{
            "path": adapter.TARGET,
            "source_sha256": adapter.engine.digest(source.read_bytes()),
            "remote_sha256": "a" * 64
        }]}), encoding="utf-8")
        return source, manifest

    def test_current_main_source_and_manifest_bind_exactly(self):
        manifest, payload, remote = adapter.guarded_load(adapter.RELEASE_ID)
        self.assertEqual(set(payload), {"private/snapshots.php"})
        self.assertEqual(remote[adapter.TARGET],
                         "ce1dcc6021ec2904474e57c710a5276cf4b08e40236b7515a749516eb40478d0")
        self.assertEqual(manifest["files"][0]["source_sha256"],
                         "3a847bc4006e3013cdca295192ef778c99f5e7fe389fb4816d6709924da46006")
        self.assertEqual(adapter.engine.digest(payload[adapter.TARGET]),
                         manifest["files"][0]["source_sha256"])
        self.assertIn("private/snapshots", adapter.engine.PROTECTED)

    def test_exact_one_file_and_fail_closed_on_source_drift(self):
        with TemporaryDirectory() as tmp:
            root = Path(tmp)
            source, file = self.fixture(root)
            original = json.loads(file.read_text())
            self.assertEqual(set(adapter.guarded_load(adapter.RELEASE_ID,source_root=root)[1]),
                             {adapter.TARGET})
            for change in [
                {"path":"private/config.php"}, {"remote_sha256":"ABSENT"},
                {"source_sha256":"f"*64}, {"unsafe":"yes"}
            ]:
                entry = dict(original["files"][0], **change)
                file.write_text(json.dumps({"schema":1,"files":[entry]}))
                with self.assertRaises(ValueError):
                    adapter.guarded_load(adapter.RELEASE_ID,source_root=root)
            file.write_text(json.dumps(original))
            source.write_bytes(b"<?php // incomplete")
            with self.assertRaisesRegex(ValueError, "source drift"):
                adapter.guarded_load(adapter.RELEASE_ID,source_root=root)
            source.write_bytes(b"<?php // 41lokeren-v8-catalog-only; SNAP_INSERT")
            doc = json.loads(json.dumps(original))
            doc["files"][0]["source_sha256"] = adapter.engine.digest(source.read_bytes())
            file.write_text(json.dumps(doc))
            with self.assertRaisesRegex(ValueError, "lacks approved"):
                adapter.guarded_load(adapter.RELEASE_ID,source_root=root)
            with self.assertRaises(ValueError):
                adapter.guarded_load("artist-publication-snapshot-20261114",source_root=root)

    def test_workflow_and_backup_gates_prevent_dispatch_without_authorization(self):
        sha="b"*40
        safe={
            "GITHUB_ACTIONS":"true", "GITHUB_EVENT_NAME":"workflow_dispatch",
            "GITHUB_REPOSITORY":"pecharko/Veiling",
            "GITHUB_WORKFLOW_REF":adapter.WORKFLOW_REF,
            "GITHUB_REF":"refs/heads/main","GITHUB_SHA":sha,
            "DEPLOY_CONFIRMATION":adapter.PREFIX+adapter.RELEASE_ID+"-"+sha,
            adapter.BACKUP_KEY:adapter.BACKUP_ATTESTATION
        }
        for bad in [
            {"GITHUB_ACTIONS":"false"}, {"GITHUB_EVENT_NAME":"push"},
            {"GITHUB_REPOSITORY":"someone/other"},
            {"GITHUB_WORKFLOW_REF":"pecharko/Veiling/.github/workflows/shared-ovh-release.yml@refs/heads/main"},
            {"GITHUB_REF":"refs/heads/preview/ui"}, {"GITHUB_SHA":"not-a-sha"},
            {"DEPLOY_CONFIRMATION":"go"}, {adapter.BACKUP_KEY:""},
            {adapter.BACKUP_KEY:"unverified"}
        ]:
            with self.subTest(bad=bad),patch.dict(os.environ,{**safe,**bad}),patch.object(
                sys,"argv",["controller","deploy"]
            ),patch.object(adapter.engine,"run",side_effect=AssertionError("No write allowed")):
                with self.assertRaises(ValueError):
                    adapter.main()

    def test_authorized_invocation_delegates_only_to_pinned_shared_engine(self):
        sha="a"*40
        confirm=adapter.PREFIX+adapter.RELEASE_ID+"-"+sha
        env={
            "GITHUB_ACTIONS":"true", "GITHUB_EVENT_NAME":"workflow_dispatch",
            "GITHUB_REPOSITORY":"pecharko/Veiling",
            "GITHUB_WORKFLOW_REF":adapter.WORKFLOW_REF,
            "GITHUB_REF":"refs/heads/main","GITHUB_SHA":sha,
            "DEPLOY_CONFIRMATION":confirm,
            adapter.BACKUP_KEY:adapter.BACKUP_ATTESTATION
        }
        observed=[]
        def fake_run():
            observed.append((sys.argv[:],os.environ["DEPLOY_CONFIRMATION"],
                             adapter.engine.load_manifest is adapter.guarded_load))
        old_loader=adapter.engine.load_manifest
        with patch.dict(os.environ,env),patch.object(sys,"argv",["controller","deploy"]),patch.object(
            adapter.engine,"run",side_effect=fake_run
        ):
            adapter.main()
            self.assertEqual(os.environ["DEPLOY_CONFIRMATION"],confirm)
        self.assertIs(adapter.engine.load_manifest,old_loader)
        self.assertEqual(observed,[(
            ["shared_ovh_release.py","deploy",adapter.RELEASE_ID],
            "DEPLOY-"+adapter.RELEASE_ID+"-"+sha,True
        )])

    def test_offline_plan_fails_closed_when_manifest_absent(self):
        with patch.object(sys,"argv",["controller","plan"]),patch.object(
            adapter.engine,"connect",side_effect=AssertionError("No network in plan")
        ),patch.object(adapter.engine,"run",side_effect=ValueError("manifest missing")):
            with self.assertRaisesRegex(ValueError,"manifest missing"):
                adapter.main()


if __name__=="__main__":
    unittest.main()
