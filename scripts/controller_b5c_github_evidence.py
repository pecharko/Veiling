#!/usr/bin/env python3
"""B5c: compose existing bounded GitHub collector and offline B5b reporter.

Caller provides a GitHub Actions:read token and an already captured B4
observation/execution metadata. No dispatch, OVH access or acceptance writes.
"""
from controller_evidence_collector import collect
from controller_b5b_offline_report import render


def collect_and_render(*, release_id, release_sha, release_run_id, expected_paths,
                       authorized_main_sha, observation, execution_run, token,
                       collector=collect):
    if not isinstance(token, str) or not token:
        raise ValueError("Explicit Actions:read token required")
    base = collector(release_run_id, release_sha, expected_paths, token)
    return render({
        "base_report": base,
        "observation": observation,
        "execution_run": execution_run,
        "release_id": release_id,
        "release_sha": release_sha,
        "release_run_id": release_run_id,
        "expected_paths": expected_paths,
        "authorized_main_sha": authorized_main_sha,
    })
