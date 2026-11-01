# Single-PR manifest preparation from a verified deploy

For an ordinary allowlisted PHP/CSS/JS file, previously deployed successfully, the new offline tool can build the next manifest directly in the **same code PR**.

Command (offline):
```sh
python3 scripts/single_pr_verified_readback_manifest.py \
  --source-root . \
  --verified-job-log authenticated-previous-deploy.log \
  --paths catalogus-public/admin/statistieken.php \
  --output releases/next-release-id.json
```

**Trust boundary:** The text log is untrusted input. The tool only parses the exact single `VERIFIED <path> <sha256>` line, validates the allowlist, and calculates candidate source hashes. Before using its output, independently authenticate the successful production workflow_dispatch, immutable commit, log source, evidence/rollback artifacts, and ensure no newer write or manual change to the file is known. A missing/duplicate readback fails closed. Prefer a fresh read-only remote baseline if provenance is uncertain.

The generated JSON must be checked in alongside code/tests in one isolated development PR; re-run CI, review exact diff, and obtain the one **separate merge approval**. After merge, check main push-CI, then obtain one **separate production approval** for the exact main SHA. The unchanged release workflow rechecks remote drift immediately before upload and preserves backup, rollback and readback. No automatic dispatch, reduced permissions, source execution with OVH secrets, controller change or production write occurs in this stage.
