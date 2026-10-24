#!/usr/bin/env python3
"""Regression guard: missing parent history must not silently skip OVH read-only preflight."""
from pathlib import Path
import re

workflow = (Path(__file__).resolve().parents[1] / ".github/workflows/lot-sale-auto-readonly-preflight.yml").read_text()
assert "fetch-depth: 0" in workflow, "Complete git ancestry required"
assert "git cat-file -e 'HEAD^{commit}'" in workflow, "Missing parent must fail closed"
assert "git diff --name-only HEAD^ HEAD" in workflow, "Compare first parent of merge"
assert 'echo "No parent revision; skip remote preflight."' not in workflow
assert "grep -Eq '^(private/lot_form[.]php|private/snapshots[.]php|catalogus-public/admin/kavel[.]php|" in workflow
assert "workflow_dispatch:" in workflow and "workflow_run:" in workflow
assert "sftp.open(" not in workflow and " mode: deploy" not in workflow
print("OK: git history and read-only skip regression guards")
