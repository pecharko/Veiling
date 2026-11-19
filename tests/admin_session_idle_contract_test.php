<?php
declare(strict_types=1);
/** Offline structural regression: no production DB or network access. */
$root = dirname(__DIR__);
$files = [
  'private/bootstrap.php',
  'catalogus-public/admin/login.php',
  'catalogus-public/admin/sessie-verlengen.php',
  'catalogus-public/admin/session-idle.js',
  'catalogus-public/admin/mail-unread.js',
  'catalogus-public/admin/logout.php',
];
$src = [];
foreach ($files as $file) {
  $body = file_get_contents($root . '/' . $file);
  if (!is_string($body) || $body === '') {
    throw new RuntimeException('Missing fixture: ' . $file);
  }
  $src[$file] = $body;
}
function idle_contract(bool $ok, string $what): void {
  if (!$ok) {
    fwrite(STDERR, 'FAIL: ' . $what . PHP_EOL);
    exit(1);
  }
}
$bootstrap = $src['private/bootstrap.php'];
$endpoint = $src['catalogus-public/admin/sessie-verlengen.php'];
$client = $src['catalogus-public/admin/session-idle.js'];
idle_contract(str_contains($bootstrap, "require_once __DIR__ . '/admin_idle_policy.php'"), 'pure idle policy loaded');
idle_contract(str_contains($bootstrap, 'admin_idle_expired('), 'expiry enforced server side');
idle_contract(str_contains($bootstrap, 'admin_idle_should_touch('), 'background poll excluded');
idle_contract(str_contains($bootstrap, "'sessie-verlengen.php'"), 'all roles permitted to renew');
idle_contract(str_contains($src['catalogus-public/admin/login.php'], '), 'new login starts inactivity clock');
idle_contract(str_contains($endpoint, 'if (!is_post())') && str_contains($endpoint, "http_response_code(405)"), 'renewal GET rejected');
idle_contract(strpos($endpoint, 'check_csrf();') !== false && strpos($endpoint, 'check_csrf();') < strpos($endpoint, 'require_auth();'), 'renewal CSRF before authenticated activity update');
idle_contract(str_contains($endpoint, 'admin_idle_expires_at()') && str_contains($endpoint, 'Cache-Control: no-store'), 'expiry JSON is uncached');
idle_contract(str_contains($client, '10 * 60 * 1000') && str_contains($client, "method: 'POST'"), 'warning and explicit POST renewal');
idle_contract(!str_contains($client, 'localStorage') && !str_contains($client, 'sessionStorage'), 'no form data written to browser storage');
idle_contract(str_contains($src['catalogus-public/admin/logout.php'], 'check_csrf()') && str_contains($src['catalogus-public/admin/logout.php'], 'session_destroy()'), 'existing logout protection preserved');
echo 'admin_session_idle_contract_test: OK' . PHP_EOL;
 . "_SESSION['admin_last_activity_at'] = time();"), 'new login starts inactivity clock');
idle_contract(str_contains($endpoint, 'if (!is_post())') && str_contains($endpoint, "http_response_code(405)"), 'renewal GET rejected');
idle_contract(strpos($endpoint, 'check_csrf();') !== false && strpos($endpoint, 'check_csrf();') < strpos($endpoint, 'require_auth();'), 'renewal CSRF before authenticated activity update');
idle_contract(str_contains($endpoint, 'admin_idle_expires_at()') && str_contains($endpoint, 'Cache-Control: no-store'), 'expiry JSON is uncached');
idle_contract(str_contains($client, '10 * 60 * 1000') && str_contains($client, "method: 'POST'"), 'warning and explicit POST renewal');
idle_contract(!str_contains($client, 'localStorage') && !str_contains($client, 'sessionStorage'), 'no form data written to browser storage');
idle_contract(str_contains($src['catalogus-public/admin/logout.php'], 'check_csrf()') && str_contains($src['catalogus-public/admin/logout.php'], 'session_destroy()'), 'existing logout protection preserved');
echo 'admin_session_idle_contract_test: OK' . PHP_EOL;
