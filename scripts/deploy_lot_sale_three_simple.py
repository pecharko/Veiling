#!/usr/bin/env python3
"""Three-file, explicitly approved, simple no-rollback release. Never sync directories."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import posixpath
import re
import stat
import subprocess
import tempfile

FILES = (
    "private/lot_form.php",
    "private/snapshots.php",
    "catalogus-public/admin/kavel.php",
)
ROOT = Path(__file__).resolve().parents[1]
LIMIT = 2 * 1024 * 1024

def sha(data):
    return hashlib.sha256(data).hexdigest()

def sources():
    result = {}
    for path in FILES:
        file = ROOT / path
        if not file.is_file() or file.is_symlink():
            raise RuntimeError("Invalid local release file: " + path)
        data = file.read_bytes()
        if not data or len(data) > LIMIT:
            raise RuntimeError("Invalid local file size: " + path)
        result[path] = data
    return result

def validate():
    items = sources()
    for path in FILES:
        subprocess.run(["php", "-l", str(ROOT / path)], check=True, capture_output=True)
    for test in ("tests/lot_sale_location_test.php", "tests/lot_form_test.php"):
        subprocess.run(["php", str(ROOT / test)], check=True, capture_output=True)
    return items

def connect():
    import paramiko
    host = os.environ["OVH_SFTP_HOST"].strip()
    user = os.environ["OVH_SFTP_USER"].strip()
    password = os.environ["OVH_SFTP_PASSWORD"]
    known_hosts = os.environ["OVH_SFTP_KNOWN_HOSTS"]
    base = os.environ["OVH_SFTP_BASE"].strip().rstrip("/")
    if not host or any(c in host for c in "/\\ \r\n") or not base or ".." in base.split("/") or any(c in base for c in "\\\r\n"):
        raise RuntimeError("Invalid pinned SFTP host or root")
    with tempfile.NamedTemporaryFile(mode="w", delete=False) as f:
        f.write(known_hosts + "\n")
        hostfile = f.name
    client = paramiko.SSHClient()
    try:
        client.load_host_keys(hostfile)
    finally:
        os.unlink(hostfile)
    client.set_missing_host_key_policy(paramiko.RejectPolicy())
    client.connect(hostname=host, port=22, username=user, password=password,
                   look_for_keys=False, allow_agent=False, timeout=20, auth_timeout=20)
    return client, base

def read_remote(sftp, base):
    found = {}
    for path in FILES:
        target = posixpath.join(base, path)
        info = sftp.lstat(target)
        if not stat.S_ISREG(info.st_mode) or info.st_size <= 0 or info.st_size > LIMIT:
            raise RuntimeError("Remote missing, symlinked or invalid: " + path)
        with sftp.open(target, "rb") as f:
            data = f.read(LIMIT + 1)
        if not data or len(data) > LIMIT:
            raise RuntimeError("Remote too large: " + path)
        found[path] = data
    if b"lot_form_values" not in found["private/lot_form.php"] or b"SNAP_TABLES" not in found["private/snapshots.php"] or b"lot_number" not in found["catalogus-public/admin/kavel.php"]:
        raise RuntimeError("Wrong remote root or unexpected production PHP")
    return found

def preflight(sha_value, manifest_path):
    source = validate()
    client, base = connect()
    try:
        with client.open_sftp() as sftp:
            old = read_remote(sftp, base)
            manifest = {"release_sha": sha_value,
                        "files": {p: {"remote_sha256": sha(old[p]), "source_sha256": sha(source[p])} for p in FILES}}
    finally:
        client.close()
    output = Path(manifest_path)
    if output.exists() or output.is_symlink():
        raise RuntimeError("Manifest already exists")
    output.write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n")
    print(json.dumps(manifest, indent=2))
    print("READ ONLY: nothing uploaded")

def deploy(sha_value, manifest_path):
    source = validate()
    manifest = json.loads(Path(manifest_path).read_text())
    if manifest.get("release_sha") != sha_value or set(manifest.get("files", {})) != set(FILES):
        raise RuntimeError("Preflight does not match exact approved commit and allowlist")
    for p in FILES:
        if manifest["files"][p].get("source_sha256") != sha(source[p]):
            raise RuntimeError("Changed source since preflight: " + p)
    client, base = connect()
    touched = []
    try:
        with client.open_sftp() as sftp:
            old = read_remote(sftp, base)
            for p in FILES:
                if sha(old[p]) != manifest["files"][p].get("remote_sha256"):
                    raise RuntimeError("STOP: production drift at " + p)
            for p in FILES:
                if old[p] == source[p]:
                    print("UNCHANGED " + p)
                    continue
                # No rollback is attempted in this explicitly selected profile.
                touched.append(p)
                with sftp.open(posixpath.join(base, p), "wb") as f:
                    f.write(source[p])
                    f.flush()
                with sftp.open(posixpath.join(base, p), "rb") as f:
                    written = f.read(LIMIT + 1)
                if sha(written) != sha(source[p]):
                    raise RuntimeError("STOP: post-upload checksum mismatch " + p)
                print("VERIFIED " + p + " sha256=" + sha(written))
    except BaseException:
        print("PARTIAL-RELEASE RISK: files possibly touched: " + ", ".join(touched), flush=True)
        raise
    finally:
        client.close()

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=("plan", "preflight", "deploy"))
    parser.add_argument("--release-sha", required=True)
    parser.add_argument("--manifest", default="lot-sale-three-preflight.json")
    args = parser.parse_args()
    if not re.fullmatch("[0-9a-f]{40}", args.release_sha):
        raise RuntimeError("Exact release SHA required")
    checked = subprocess.check_output(["git", "-C", str(ROOT), "rev-parse", "HEAD"], text=True).strip()
    if checked != args.release_sha:
        raise RuntimeError("Checkout SHA differs from approved release SHA")
    if args.mode == "plan":
        for path, data in validate().items():
            print(path + " sha256=" + sha(data))
        print("OFFLINE PLAN: no production connection")
    elif args.mode == "preflight":
        preflight(args.release_sha, args.manifest)
    else:
        deploy(args.release_sha, args.manifest)

if __name__ == "__main__":
    main()
