#!/usr/bin/env python3
"""Issue #224 cron one-file read-only SFTP preflight. NEVER writes to OVH."""
import hashlib
import json
import os
from pathlib import Path
import posixpath
import stat
import tempfile

FILE = "private/visitor_stats_daily_cron.php"
SENTINEL = "catalogus-public/admin/index.php"
ROOT = Path(__file__).resolve().parents[1]
LIMIT = 2 * 1024 * 1024

def digest(data):
    return hashlib.sha256(data).hexdigest()

def read_source():
    path = ROOT / FILE
    if path.is_symlink() or not path.is_file():
        raise RuntimeError("Unsafe or missing source")
    data = path.read_bytes()
    if not data or len(data) > LIMIT:
        raise RuntimeError("Invalid source file size")
    return data

def remote_file(sftp, path):
    info = sftp.lstat(path)
    if not stat.S_ISREG(info.st_mode) or info.st_size < 1 or info.st_size > LIMIT:
        raise RuntimeError("Unsafe remote file")
    with sftp.open(path, "rb") as handle:
        data = handle.read(LIMIT + 1)
    if not data or len(data) > LIMIT:
        raise RuntimeError("Invalid remote data size")
    return data

def preflight():
    import paramiko
    variables = ("OVH_SFTP_HOST", "OVH_SFTP_USER", "OVH_SFTP_PASSWORD",
                 "OVH_SFTP_KNOWN_HOSTS", "OVH_SFTP_BASE")
    values = [os.environ.get(name, "").strip() for name in variables]
    if not all(values):
        raise RuntimeError("Missing OVH preflight configuration")
    host, user, password, known_hosts, base = values
    if any(c in host for c in " /\\\r\n") or host.startswith("-"):
        raise RuntimeError("Invalid host")
    if not base.startswith("/") or ".." in base.split("/") or any(c in base for c in "\\r\n\\"):
        raise RuntimeError("Invalid remote root")
    with tempfile.NamedTemporaryFile(mode="w", delete=False) as tmp:
        tmp.write(known_hosts + "\n")
        known_hosts_file = tmp.name
    try:
        client = paramiko.SSHClient()
        client.load_host_keys(known_hosts_file)
    finally:
        os.unlink(known_hosts_file)
    client.set_missing_host_key_policy(paramiko.RejectPolicy())
    client.connect(host, username=user, password=password, port=22,
                   allow_agent=False, look_for_keys=False, timeout=20)
    try:
        with client.open_sftp() as sftp:
            sentinel = remote_file(sftp, posixpath.join(base, SENTINEL))
            if b"dashboard-table" not in sentinel:
                raise RuntimeError("Wrong OVH remote root sentinel")
            remote = remote_file(sftp, posixpath.join(base, FILE))
            manifest = {
                "release_sha": os.environ["RELEASE_SHA"],
                "workflow_run_id": os.environ["GITHUB_RUN_ID"],
                "file": FILE,
                "source_sha256": digest(read_source()),
                "remote_sha256": digest(remote),
                "read_only": True,
            }
            output = Path("issue224-cron-onefile-preflight.json")
            if output.exists():
                raise RuntimeError("Refusing to overwrite existing manifest")
            output.write_text(json.dumps(manifest, indent=2) + "\n")
            print("READ ONLY", FILE, manifest["remote_sha256"])
    finally:
        client.close()

if __name__ == "__main__":
    import sys
    if sys.argv[1:] == ["plan"]:
        print("SOURCE", FILE, digest(read_source()))
    elif sys.argv[1:] == ["preflight"]:
        preflight()
    else:
        raise SystemExit("Expected plan or preflight")
