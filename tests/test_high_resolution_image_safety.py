#!/usr/bin/env python3
"""Offline regression checks for safe high-resolution lot photo handling."""
from pathlib import Path

images = Path("private/images.php").read_text()
editor = Path("catalogus-public/admin/kavel.php").read_text()
assert "16 * 1024 * 1024" in images
assert "48000000" in images
assert "min(1, 1800 / max(" in images
assert "imagewebp($output, $dest, 83)" in images
assert "memory_get_usage(true) + $required > $limit" in images
assert "Automatische foto-optimalisatie is niet beschikbaar" in images
assert "foto vraagt te veel servergeheugen" in images
assert "copy($inputFile" not in images
assert "48 MP" in editor and "16 MB" in editor
assert "photos[]" in editor
print("PASS: high-resolution upload safety contract")
