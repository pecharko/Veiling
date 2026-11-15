#!/usr/bin/env python3
"""Independent *read-only* OVH hash collector core.

Not wired to GitHub Actions or production. The caller must provide a separately
authenticated, pinned-hostkey, read-only SFTP session, a reviewed remote base,
and immutable deployment evidence. Never import or reuse uploader credentials.
"""
import hashlib
import posixpath
import re
import stat

from ovh_deploy_evidence_contract import validate as validate_uploader
from shared_ovh_release import ALLOWED_SUFFIX, PROTECTED, HASH

MAX_FILE = 8 * 1024 * 1024
COLLECTION_ID = re.compile(r"^[a-z0-9][a-z0-9-]{7,80}$")
SAFE_PART = re.compile(r"^[A-Za-z0-9_.-]+$")


def validate_root(root):
    if (not isinstance(root, str) or not root.startswith("/") or root == "/"
            or "\\" in root or any(p in ("", ".", "..") for p in root[1:].split("/"))
            or any(not SAFE_PART.fullmatch(p) or p.startswith(".") for p in root[1:].split("/"))):
        raise ValueError("Invalid independent readback root")
    return root.rstrip("/")


def validate_path(path):
    if (not isinstance(path, str) or not path.startswith(("private/", "catalogus-public/"))
            or path.startswith("/") or "\\" in path
            or any(not SAFE_PART.fullmatch(p) or p in ("", ".", "..") or p.startswith(".")
                   for p in path.split("/"))):
        raise ValueError("Unsafe independent readback target")
    if not path.endswith(ALLOWED_SUFFIX) or any(path.startswith(p) for p in PROTECTED):
        raise ValueError("Protected or unsupported release target")
    return path


def validate_manifest(upload, manifest):
    """Require all paths and source/baseline hashes match a separately reviewed manifest.

    The caller must authenticate the immutable GitHub provenance of *both* inputs.
    This function is a consistency gate, not an attestation of provenance.
    """
    if not isinstance(manifest, dict) or set(manifest) != {"schema", "files"} or type(manifest["schema"]) is not int or manifest["schema"] != 1:
        raise ValueError("Invalid release manifest")
    entries = manifest["files"]
    if not isinstance(entries, list) or len(entries) != len(upload["files"]):
        raise ValueError("Manifest file count mismatch")
    expected = {}
    for item in entries:
        if not isinstance(item, dict) or set(item) != {"path", "source_sha256", "remote_sha256"}:
            raise ValueError("Invalid release manifest entry")
        path = validate_path(item["path"])
        if path in expected:
            raise ValueError("Duplicate manifest path")
        if not isinstance(item["source_sha256"], str) or not HASH.fullmatch(item["source_sha256"]):
            raise ValueError("Invalid manifest source digest")
        baseline = item["remote_sha256"]
        if not isinstance(baseline, str) or (baseline != "ABSENT" and not HASH.fullmatch(baseline)):
            raise ValueError("Invalid manifest baseline digest")
        expected[path] = (item["source_sha256"], baseline)
    observed = {row["path"]: (row["source_sha256"], row["baseline_sha256"]) for row in upload["files"]}
    if expected != observed:
        raise ValueError("Uploader evidence differs from approved manifest")
    return list(expected)


def check_parent_directories(sftp, base, path):
    # Reject symlinked parent segments; every component must be a directory.
    current = ""
    for segment in (base.strip("/") + "/" + posixpath.dirname(path)).split("/"):
        current += "/" + segment
        info = sftp.lstat(current)
        if not stat.S_ISDIR(info.st_mode):
            raise ValueError("Unsafe remote directory or symlink")


def read_one(sftp, full_path):
    """Only lstat + open(rb) are allowed. Never write, remove or rename."""
    info = sftp.lstat(full_path)
    if (not stat.S_ISREG(info.st_mode) or type(info.st_size) is not int
            or not 0 < info.st_size <= MAX_FILE):
        raise ValueError("Remote file missing, symbolic or too large")
    with sftp.open(full_path, "rb") as stream:
        hasher = hashlib.sha256()
        count = 0
        while True:
            block = stream.read(min(65536, MAX_FILE + 1 - count))
            if not block:
                break
            count += len(block)
            if count > MAX_FILE or count > info.st_size:
                raise ValueError("Remote changed while reading")
            hasher.update(block)
    if count != info.st_size:
        raise ValueError("Remote changed while reading")
    after = sftp.lstat(full_path)
    if (not stat.S_ISREG(after.st_mode) or after.st_size != info.st_size
            or getattr(after, "st_mtime", None) != getattr(info, "st_mtime", None)):
        raise ValueError("Remote changed during collection")
    return hasher.hexdigest()


def collect(sftp, remote_root, upload, collection_id, manifest):
    """Collect independently supplied read-only SFTP hashes for exact files.

    This output is a *claim*, not an authenticated attestation by itself.
    """
    upload = validate_uploader(upload)
    if upload["status"] != "VERIFIED":
        raise ValueError("Deployment is not verified")
    if not isinstance(collection_id, str) or not COLLECTION_ID.fullmatch(collection_id):
        raise ValueError("Invalid collection id")
    base = validate_root(remote_root)
    paths = validate_manifest(upload, manifest)
    # Prevalidate all paths *before* any network read.
    rows = []
    for path in paths:
        remote_path = posixpath.join(base, path)
        if not remote_path.startswith(base + "/"):
            raise ValueError("Remote root escape")
        check_parent_directories(sftp, base, path)
        rows.append({"path": path, "remote_sha256": read_one(sftp, remote_path)})
    return {
        "schema": 1,
        "release_id": upload["release_id"],
        "release_sha": upload["release_sha"],
        "run_id": upload["run_id"],
        "origin": "separate-read-only-sftp-collector",
        "collection_id": collection_id,
        "status": "COLLECTED",
        "files": rows,
    }
