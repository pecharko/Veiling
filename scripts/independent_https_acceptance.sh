#!/usr/bin/env bash
# Independent read-only HTTPS acceptance from a trusted ordinary client network.
# Run locally (not in GitHub-hosted Actions) while OVH Application Firewall remains enabled.
set -euo pipefail
base='https://41lokeren.be'
failed=0
for item in '/:200' '/admin/login.php:200'; do
  path="${item%:*}"; expected="${item##*:}"
  status='000'
  if status="$(curl --silent --show-error --max-time 20 --connect-timeout 10 --output /dev/null --write-out '%{http_code}' "${base}${path}")"; then
    printf '%s %s expected=%s\n' "$path" "$status" "$expected"
    if [[ "$status" != "$expected" ]]; then failed=1; fi
  else
    printf 'TRANSPORT FAILURE %s\n' "$path" >&2
    failed=1
  fi
done
if (( failed )); then
  echo 'FAIL: independent HTTPS acceptance is NOT satisfied.' >&2
  exit 1
fi
echo 'PASS: independent client observed expected HTTPS statuses.'
echo 'This is external browser/network evidence, NOT a successful GitHub-runner HTTP gate.'
