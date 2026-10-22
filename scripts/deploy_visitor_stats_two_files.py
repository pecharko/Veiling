#!/usr/bin/env python3
"""Manual new-files-only statistics release; never overwrites or rolls back."""
import argparse
import hashlib
import os
from pathlib import Path
import posixpath
import stat
import sys
from deploy_ovh_sftp import connect, remote_root, MAX_FILE_SIZE

ROOT = Path(__file__).resolve().parents[1]
FILES = (
    'private/visitor_stats_archive_one_day_experimental.php',
    'catalogus-public/admin/statistieken.php',
)
MARKERS = (
    ('private/bootstrap.php', b'function admin_top'),
    ('catalogus-public/admin/index.php', b'Kaveloverzicht'),
)

def sources():
    result = {}
    for name in FILES:
        path = ROOT / name
        if not path.is_file() or path.is_symlink():
            raise RuntimeError('Invalid release source: ' + name)
        data = path.read_bytes()
        if not data or len(data) > MAX_FILE_SIZE:
            raise RuntimeError('Invalid file size: ' + name)
        result[name] = data
    return result

def check_remote(sftp, root):
    for name, marker in MARKERS:
        target = posixpath.join(root, name)
        info = sftp.lstat(target)
        if not stat.S_ISREG(info.st_mode) or info.st_size > MAX_FILE_SIZE:
            raise RuntimeError('Unexpected application root: ' + name)
        with sftp.open(target, 'rb') as file:
            if marker not in file.read(MAX_FILE_SIZE + 1):
                raise RuntimeError('Application root marker mismatch: ' + name)
    for name in FILES:
        try:
            sftp.lstat(posixpath.join(root, name))
        except FileNotFoundError:
            continue
        raise RuntimeError('STOP: new installation target already exists: ' + name)

def main(mode):
    data = sources()
    for name, content in data.items():
        print('Local SHA256: ' + name + ' ' + hashlib.sha256(content).hexdigest())
    if mode == 'plan':
        print('Offline-only plan: no connection, no upload')
        return
    if os.environ.get('VISITOR_RELEASE_CONFIRMATION') != 'DEPLOY VISITOR STATS TWO NEW FILES':
        raise RuntimeError('Missing separate final deploy confirmation')
    client = connect()
    try:
        with client.open_sftp() as sftp:
            root = remote_root()
            check_remote(sftp, root)
            for name, content in data.items():
                target = posixpath.join(root, name)
                # SFTP exclusive-create mode ('x'): fails if target exists, including a symlink.
                # Errors STOP, without any attempt to delete or overwrite partial uploads.
                with sftp.open(target, 'x') as remote:
                    remote.write(content)
                    remote.flush()
                info = sftp.lstat(target)
                if not stat.S_ISREG(info.st_mode) or info.st_size != len(content):
                    raise RuntimeError('Unexpected remote type or size: ' + name)
                with sftp.open(target, 'rb') as remote:
                    actual = remote.read(MAX_FILE_SIZE + 1)
                if hashlib.sha256(actual).digest() != hashlib.sha256(content).digest():
                    raise RuntimeError('Remote SHA256 mismatch: ' + name)
                print('Verified remote SHA256: ' + name)
    finally:
        client.close()

if __name__ == '__main__':
    try:
        parser = argparse.ArgumentParser()
        parser.add_argument('mode', choices=('plan', 'deploy'))
        main(parser.parse_args().mode)
    except Exception as exc:
        print('RELEASE STOP: ' + str(exc), file=sys.stderr)
        sys.exit(1)
