#!/usr/bin/env python3
"""Isolated source contract tests; does not touch production."""
import importlib.util
from pathlib import Path

path = Path(__file__).resolve().parents[1] / "scripts/plan_visitor_stats_three_file.py"
spec = importlib.util.spec_from_file_location("plan3", path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
result = module.plan()
assert result["mode"] == "offline_only"
assert result["upload_allowed"] is False
assert result["database_writes_allowed"] is False
assert result["production_schema_verified"] is False
assert result["production_diff_reviewed"] is False
assert len(result["sources"]) == 3
assert {x["path"] for x in result["sources"]} == set(module.FILES)
assert all(x["diff_bytes_obtained"] is False for x in result["sources"])
print("PASS: offline three-file tracking contract; no admin tracking")
