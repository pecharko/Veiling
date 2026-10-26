#!/usr/bin/env bash
# Read-only HTTP gate; preserve the 200 requirements and never mask WAF 403.
set -euo pipefail
failed=0
for path in / /admin/login.php; do
  url="https://41lokeren.be${path}"
  if actual=$(curl --silent --show-error --max-time 20 --output /dev/null --write-out '%{http_code}' "$url"); then
    printf 'HTTPS gate: %s HTTP %s (expected 200)\n' "$path" "$actual"
    if [[ "$actual" != 200 ]]; then
      failed=1
      if [[ "$actual" == 403 ]]; then
        echo 'HTTP 403: runner request blocked; verify OVH access/WAF rules without disabling access protections.' >&2
      fi
    fi
  else
    echo "HTTPS gate: $path transport error" >&2
    failed=1
  fi
done
if (( failed )); then
  echo 'HTTPS gate failed. Fail closed: no deployment may proceed.' >&2
  exit 1
fi
