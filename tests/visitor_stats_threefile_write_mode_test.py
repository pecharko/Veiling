#!/usr/bin/env python3
"""Offline regression: new helper creation must be exclusive AND writable."""
import ast
from pathlib import Path
source = Path(__file__).resolve().parents[1] / "scripts/deploy_visitor_stats_threefile_guarded.py"
tree = ast.parse(source.read_text())
values = []
for node in ast.walk(tree):
    if isinstance(node, ast.Assign) and any(isinstance(t, ast.Name) and t.id == "mode_sftp" for t in node.targets):
        if isinstance(node.value, ast.Constant):
            values.append(node.value.value)
assert values == ["wxb", "wb"], "SFTP helper must be exclusive-writable; existing file writable"
assert "x" in values[0] and "w" in values[0] and "b" in values[0]
assert "x" not in values[1]
print("PASS exclusive create is writable and existing writes remain binary")
