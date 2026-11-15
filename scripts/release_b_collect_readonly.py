#!/usr/bin/env python3
"""Opt-in independent read-only SFTP collection, never called by deployment CI.

Requires a separately provisioned READ-ONLY SFTP account and pinned known-hosts
value. The release uploader's OVH_SFTP_* credentials are never referenced.
No implicit scheduler, retries, writer API, admin login or production mutation.
"""
import json
import os
import re
import sys
import tempfile
from pathlib import Path

from release_b_independent_sftp import collect, validate_root, validate_manifest
from ovh_deploy_evidence_contract import validate as validate_uploader

HOST = re.compile(r"^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$")
USER = re.compile(r"^[A-Za-z0-9_.@-]{1,128}$")

def config(env):
    required = ("OVH_READONLY_SFTP_HOST", "OVH_READONLY_SFTP_USER",
                "OVH_READONLY_SFTP_PASSWORD", "OVH_READONLY_SFTP_KNOWN_HOSTS",
                "OVH_READONLY_SFTP_BASE")
    if any(not isinstance(env.get(key), str) or not env[key].strip() for key in required):
        raise ValueError("Separate read-only SFTP account and pinned hostkey required")
    host = env["OVH_READONLY_SFTP_HOST"]
    user = env["OVH_READONLY_SFTP_USER"]
    known = env["OVH_READONLY_SFTP_KNOWN_HOSTS"]
    if not HOST.fullmatch(host) or not USER.fullmatch(user):
        raise ValueError("Invalid independent SFTP endpoint")
    if any(c in known for c in ("\x00", "\r")) or "\n" in known or len(known) > 8192:
        raise ValueError("Expected a single pinned known-hosts entry")
    if not known.startswith(host + " ") and not known.startswith("[" + host + "]:22 "):
        raise ValueError("Host key does not bind to configured host")
    root = validate_root(env["OVH_READONLY_SFTP_BASE"])
    return host, user, env["OVH_READONLY_SFTP_PASSWORD"], known, root

def read_from_independent_account(env, upload, collection_id, manifest):
    # Validate untrusted evidence *before* opening a network session.
    validate_uploader(upload)
    validate_manifest(upload, manifest)
    host, user, password, known, root = config(env)
    import paramiko
    with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", delete=False) as stream:
        os.chmod(stream.name, 0o600)
        stream.write(known + "\n")
        path = stream.name
    client = paramiko.SSHClient()
    try:
        client.load_host_keys(path)
    finally:
        os.unlink(path)
    client.set_missing_host_key_policy(paramiko.RejectPolicy())
    try:
        client.connect(hostname=host, port=22, username=user, password=password,
                       allow_agent=False, look_for_keys=False,
                       timeout=20, auth_timeout=20, banner_timeout=20)
        with client.open_sftp() as sftp:
            return collect(sftp, root, upload, collection_id, manifest)
    finally:
        client.close()

def main():
    if len(sys.argv) != 4:
        raise ValueError("Usage: release_b_collect_readonly.py uploader-evidence.json immutable-manifest.json collection-id")
    evidence_file = Path(sys.argv[1])
    if evidence_file.is_symlink() or not evidence_file.is_file() or evidence_file.stat().st_size > 131072:
        raise ValueError("Invalid uploader evidence file")
    upload = json.loads(evidence_file.read_text(encoding="utf-8"))
    manifest_file = Path(sys.argv[2])
    if manifest_file.is_symlink() or not manifest_file.is_file() or not 0 < manifest_file.stat().st_size <= 65536:
        raise ValueError("Invalid immutable manifest file")
    manifest = json.loads(manifest_file.read_text(encoding="utf-8"))
    report = read_from_independent_account(os.environ, upload, sys.argv[3], manifest)
    print(json.dumps(report, sort_keys=True))

if __name__ == "__main__":
    main()
