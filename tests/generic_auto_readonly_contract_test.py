#!/usr/bin/env python3
import importlib.util
from pathlib import Path

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("matrix", root / "scripts/auto_readonly_release_matrix.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
profiles = module.profiles()
assert {p["id"] for p in profiles} == {"kavelnummer-thumbnails", "veiling-organisatie"}
yaml = (root / ".github/workflows/registered-release-auto-readonly.yml").read_text()
assert "workflow_run:" in yaml and "conclusion == 'success'" in yaml
assert "git rev-parse FETCH_HEAD" in yaml
assert "matrix.script" in yaml and "preflight" in yaml
assert "upload-artifact@v4" in yaml and "workflow_dispatch:" in yaml
assert "mode=deploy" not in yaml and " gh workflow run " not in yaml
print("PASS registered generic read-only release contract")
