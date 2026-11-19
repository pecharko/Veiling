<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/admin_idle_policy.php';

function expect_idle(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
    exit(1);
  }
}
$now = 1_800_000_000;
expect_idle(ADMIN_IDLE_SECONDS === 3600, '60 minute idle limit');
expect_idle(!admin_idle_expired(null, $now), 'legacy authenticated session receives a new clock');
expect_idle(!admin_idle_expired($now - 3599, $now), 'one second before expiry');
expect_idle(admin_idle_expired($now - 3600, $now), 'exact boundary expires');
expect_idle(admin_idle_expired($now - 7200, $now), 'old session expires');
expect_idle(admin_idle_expired($now + 61, $now), 'far-future corrupted clock fails closed');
expect_idle(!admin_idle_expired($now + 60, $now), 'small clock skew tolerated');
expect_idle(admin_idle_expiry($now - 120, $now) === $now + 3480, 'remaining interval');
expect_idle(admin_idle_expiry(null, $now) === $now + 3600, 'initial interval');
expect_idle(!admin_idle_should_touch('/admin/mail-ongelezen.php'), 'automatic mail polling cannot extend session');
expect_idle(admin_idle_should_touch('/admin/index.php'), 'interactive admin page extends');
expect_idle(admin_idle_should_touch('/admin/sessie-verlengen.php'), 'explicit renewal extends');
echo "admin_idle_policy_test: OK" . PHP_EOL;
