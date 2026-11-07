#!/usr/bin/env python3
"""Pinned read-only SFTP baselines for the canonical-domain release.

Does not change remote files and never prints file bytes or credentials.
"""
import json
import posixpath

from scripts import shared_ovh_release as engine

TARGETS = (
    "catalogus-public/.htaccess",
    "catalogus-public/index.php",
    "catalogus-public/kavel.php",
    "catalogus-public/pecharko.php",
    "catalogus-public/vorige-editie.php",
)


def collect(connect=engine.connect):
    client, base = connect()
    try:
        with client.open_sftp() as sftp:
            sentinel = engine.read_remote(
                sftp, posixpath.join(base, "catalogus-public/admin/index.php")
            )
            if sentinel is None or b"dashboard-table" not in sentinel:
                raise RuntimeError("Incorrect remote root")
            result = {}
            for name in TARGETS:
                data = engine.read_remote(sftp, posixpath.join(base, name))
                result[name] = "ABSENT" if data is None else engine.digest(data)
            return result
    finally:
        client.close()


def main():
    print(json.dumps(collect(), indent=2, sort_keys=True))


if __name__ == "__main__":
    main()
