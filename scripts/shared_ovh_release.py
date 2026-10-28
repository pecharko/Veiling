#!/usr/bin/env python3
"""Shared, fail-closed OVH code-only release. Never executes uploaded code."""
import hashlib
import json
import os
from pathlib import Path
import posixpath
import re
import stat
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
BACKUP = ROOT / "deployment-backups"
MAX_FILE = 8 * 1024 * 1024
MAX_FILES = 25
HASH = re.compile(r"^[a-f0-9]{64}$")
ID = re.compile(r"^[a-z0-9][a-z0-9-]{2,60}$")
ALLOWED_PREFIX = ("catalogus-public/", "private/")
ALLOWED_SUFFIX = (".php", ".css", ".js", ".svg", ".webp", ".png", ".jpg", ".jpeg", ".html")
PROTECTED = (".github/", "scripts/", "tests/", "private/.env", "private/config",
             "private/snapshots", "private/visitor_stats_daily_cron.php")


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def load_manifest(release_id, *, source_root=None):
    if not ID.fullmatch(release_id):
        raise ValueError("Invalid release id")
    root = ROOT if source_root is None else source_root
    path = root / "releases" / (release_id + ".json")
    if path.is_symlink() or not path.is_file():
        raise ValueError("Release manifest missing")
    manifest = json.loads(path.read_text(encoding="utf-8"))
    if set(manifest) != {"schema", "files"} or manifest["schema"] != 1:
        raise ValueError("Invalid manifest schema")
    entries = manifest["files"]
    if not isinstance(entries, list) or not 1 <= len(entries) <= MAX_FILES:
        raise ValueError("Invalid file count")
    payload = {}
    remote = {}
    for entry in entries:
        if not isinstance(entry, dict) or set(entry) != {"path", "source_sha256", "remote_sha256"}:
            raise ValueError("Invalid file entry")
        name, source_sha, remote_sha = (entry[k] for k in ("path", "source_sha256", "remote_sha256"))
        if not isinstance(name, str) or not name.startswith(ALLOWED_PREFIX) or not name.endswith(ALLOWED_SUFFIX):
            raise ValueError("Not an allowed runtime file")
        if (name.startswith("/") or "\\" in name or any(part in ("", ".", "..") or part.startswith(".") for part in name.split("/"))
                or any(name.startswith(blocked) for blocked in PROTECTED) or name in payload):
            raise ValueError("Invalid or protected path")
        if not isinstance(source_sha, str) or not HASH.fullmatch(source_sha):
            raise ValueError("Invalid source SHA")
        if not isinstance(remote_sha, str) or (remote_sha != "ABSENT" and not HASH.fullmatch(remote_sha)):
            raise ValueError("Invalid remote SHA")
        src = root / name
        if src.is_symlink() or not src.is_file():
            raise ValueError("Missing/unsafe source")
        data = src.read_bytes()
        if not data or len(data) > MAX_FILE or digest(data) != source_sha:
            raise ValueError("Source SHA or size mismatch: " + name)
        payload[name] = data
        remote[name] = remote_sha
    return manifest, payload, remote


def connect():
    import paramiko
    keys = ("OVH_SFTP_HOST", "OVH_SFTP_USER", "OVH_SFTP_PASSWORD",
            "OVH_SFTP_KNOWN_HOSTS", "OVH_SFTP_BASE")
    values = [os.environ.get(k, "").strip() for k in keys]
    if not all(values):
        raise ValueError("Missing SFTP configuration")
    host, user, password, known, base = values
    if any(c in host for c in " /\\\r\n") or host.startswith("-"):
        raise ValueError("Invalid host")
    if not base.startswith("/") or "\\" in base or any(x in (".", "..") for x in base.split("/")):
        raise ValueError("Invalid remote root")
    with tempfile.NamedTemporaryFile(mode="w", delete=False) as f:
        f.write(known + "\n")
        keyfile = f.name
    try:
        client = paramiko.SSHClient()
        client.load_host_keys(keyfile)
    finally:
        os.unlink(keyfile)
    client.set_missing_host_key_policy(paramiko.RejectPolicy())
    client.connect(host, port=22, username=user, password=password,
                   allow_agent=False, look_for_keys=False, timeout=20)
    return client, base.rstrip("/")


def read_remote(sftp, path):
    try:
        info = sftp.lstat(path)
    except OSError as exc:
        if getattr(exc, "errno", None) == 2:
            return None
        raise
    if not stat.S_ISREG(info.st_mode) or info.st_size > MAX_FILE:
        raise RuntimeError("Remote not a safe regular file")
    with sftp.open(path, "rb") as f:
        data = f.read(MAX_FILE + 1)
    if not data or len(data) > MAX_FILE:
        raise RuntimeError("Invalid remote length")
    return data


def run():
    if len(sys.argv) != 3 or sys.argv[1] not in ("plan", "deploy"):
        raise ValueError("Usage: shared_ovh_release.py plan|deploy <release-id>")
    mode, release_id = sys.argv[1:]
    manifest, payload, expected = load_manifest(release_id)
    for name, data in payload.items():
        print("SOURCE", name, digest(data), "EXPECTED_REMOTE", expected[name])
    if mode == "plan":
        return
    sha = os.environ.get("GITHUB_SHA", "")
    confirmation = os.environ.get("DEPLOY_CONFIRMATION", "")
    if confirmation != "DEPLOY-" + release_id + "-" + sha:
        raise ValueError("Exact release authorization missing")
    evidence = {
        "schema": 1, "release_id": release_id, "release_sha": sha,
        "run_id": int(os.environ.get("GITHUB_RUN_ID", "0")),
        "status": "NOT_STARTED", "rollback_artifact": None,
        "browser_acceptance": "PENDING",
        "files": [{"path": name, "source_sha256": digest(data),
                   "baseline_sha256": expected[name], "readback_sha256": None}
                  for name, data in payload.items()]
    }
    evidence_rows = {row["path"]: row for row in evidence["files"]}
    client = None
    try:
        client, base = connect()
        with client.open_sftp() as sftp:
            sentinel = read_remote(sftp, posixpath.join(base, "catalogus-public/admin/index.php"))
            if sentinel is None or b"dashboard-table" not in sentinel:
                raise RuntimeError("Incorrect remote root")
            prior = {}
            for name in payload:
                path = posixpath.join(base, name)
                prior[name] = read_remote(sftp, path)
                actual = "ABSENT" if prior[name] is None else digest(prior[name])
                if actual != expected[name]:
                    raise RuntimeError("STOP: production drift: " + name)
                print("BASELINE OK", name, actual)
            if BACKUP.exists():
                raise RuntimeError("Backup directory already exists")
            BACKUP.mkdir(mode=0o700)
            for name, data in prior.items():
                if data is not None:
                    target = BACKUP / name
                    target.parent.mkdir(parents=True, exist_ok=True)
                    target.write_bytes(data)
            (BACKUP / "manifest.json").write_text(json.dumps({
                "release_id": release_id, "release_sha": sha,
                "previous": expected, "source": {p: digest(v) for p, v in payload.items()}
            }, indent=2))
            changed = []
            try:
                for name, data in payload.items():
                    if prior[name] == data:
                        print("UNCHANGED", name)
                        evidence_rows[name]["readback_sha256"] = digest(prior[name])
                        continue
                    dest = posixpath.join(base, name)
                    changed.append(name)
                    with sftp.open(dest, "wb") as f:
                        f.write(data)
                        f.flush()
                    after = read_remote(sftp, dest)
                    if after != data:
                        raise RuntimeError("Upload verification failed: " + name)
                    evidence_rows[name]["readback_sha256"] = digest(after)
                    print("VERIFIED", name, digest(after))
            except Exception:
                rollback_failed = False
                for name in reversed(changed):
                    dest = posixpath.join(base, name)
                    try:
                        if prior[name] is None:
                            sftp.remove(dest)
                        else:
                            with sftp.open(dest, "wb") as f:
                                f.write(prior[name])
                                f.flush()
                        if read_remote(sftp, dest) != prior[name]:
                            raise RuntimeError("Rollback verification failed")
                        print("ROLLBACK VERIFIED", name)
                    except Exception as err:
                        rollback_failed = True
                        print("CRITICAL MANUAL RESTORE", name, repr(err), file=sys.stderr)
                evidence["status"] = "ROLLBACK_FAILED" if rollback_failed else "ROLLED_BACK"
                raise
            evidence["status"] = "VERIFIED"
    except Exception:
        if evidence["status"] == "NOT_STARTED":
            evidence["status"] = "FAILED"
        raise
    finally:
        if client is not None:
            client.close()
        from ovh_deploy_evidence_contract import validate
        path = ROOT / "deployment-evidence.json"
        path.write_text(json.dumps(validate(evidence), indent=2) + "\n", encoding="utf-8")


if __name__ == "__main__":
    try:
        run()
    except Exception as exc:
        print("SHARED RELEASE STOPPED:", exc, file=sys.stderr)
        sys.exit(1)
