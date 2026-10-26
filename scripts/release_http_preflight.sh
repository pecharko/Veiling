#!/usr/bin/env bash
# Read-only HTTPS gate. Run before *any* production file writes.
set -euo pipefail
host="${SMOKE_BASE_URL:-https://41lokeren.be}"
failed=0
check() {
  local path="$1" expected="$2" actual
  if ! actual=$(curl --silent --show-error --max-time 20 --output /dev/null --write-out '%{http_code}' "$host$path"); then
    printf 'HTTP-PREFLIGHT %s: transport failed\n' "$path" >&2
    failed=1
    return
  fi
  printf 'HTTP-PREFLIGHT %s: HTTP %s (expected %s)\n' "$path" "$actual" "$expected"
  if [[ "$actual" != "$expected" ]]; then
    if [[ "$actual" == 403 ]]; then
      printf 'BLOCKED: runner receives HTTP 403; investigate WAF/hosting access. Never treat 403 as a passing smoke test.\n' >&2
    fi
    failed=1
  fi
}
check / 200
check /admin/login.php 200
check /admin/veiling-organisatie.php 303
check /admin/rollen.php 303
if ((failed)); then
  echo 'HTTP preflight failed: no production upload is authorized by these tests.' >&2
  exit 1
fi
echo 'HTTP preflight passed.'
