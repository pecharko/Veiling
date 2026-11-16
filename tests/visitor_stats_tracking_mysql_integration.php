<?php
declare(strict_types=1);
// Disposable localhost CI only. GeoLite2 is intentionally absent in this
// isolated harness: unresolved IPs must not create any analytics records.
$dsn = (string)(getenv('ANALYTICS_TEST_DSN') ?: '');
if (!str_starts_with($dsn, 'mysql:host=127.0.0.1;port=3306;dbname=visitor_stats_ci;')
    || !str_contains($dsn, 'charset=utf8mb4')) {
    fwrite(STDERR, "STOP: only disposable localhost CI MySQL is permitted\n");
    exit(2);
}
$pdo = new PDO($dsn, (string)getenv('ANALYTICS_TEST_USER'), (string)getenv('ANALYTICS_TEST_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('DROP TABLE IF EXISTS visitor_stats_daily');
$pdo->exec('CREATE TABLE visitor_stats_daily (
    visit_date DATE NOT NULL, visitor_id BINARY(32) NOT NULL,
    page_kind VARCHAR(8) NOT NULL, lot_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    views INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY(visit_date, visitor_id, page_kind, lot_id)
) ENGINE=InnoDB');
$secret = str_repeat('a', 64);
function cfg(string $key): ?string { global $secret; return $key === 'analytics_secret' ? $secret : null; }
function db(): PDO { global $pdo; return $pdo; }
require dirname(__DIR__) . '/private/visitor_stats.php';
$ipSql = preg_replace('/^\\s*--[^\\n]*$/m', '', (string)file_get_contents(
    dirname(__DIR__).'/database/migrations/20261102_visitor_stats_ip_exclusions.sql'
));
foreach (explode(';', (string)$ipSql) as $sql) if (trim($sql) !== '') $pdo->exec($sql);
$pdo->exec('DELETE FROM visitor_stats_ip_exclusions');
function failUnless(bool $condition, string $description): void {
    if (!$condition) throw new RuntimeException($description);
}
function checkNoStats(PDO $pdo, string $description): void {
    failUnless((int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_daily')->fetchColumn() === 0, $description);
}
failUnless(analytics_ip_exclusion_enabled($pdo), 'Fresh exclusion migration must default ON');
analytics_ip_set_exclusion($pdo, true);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '192.0.2.1'; // Documentation IP: unresolved country.
$_SERVER['HTTP_USER_AGENT'] = 'CI integration browser';
unset($_SERVER['HTTP_DNT'], $_SERVER['HTTP_X_FORWARDED_FOR']);
track_catalog_view();
track_catalog_view();
track_catalog_view(7);
checkNoStats($pdo, 'Unknown-origin home and lot views must not be stored');

$_SERVER['REMOTE_ADDR'] = '192.0.2.2';
track_catalog_view();
checkNoStats($pdo, 'Additional unresolved visitors must be excluded');
$_SERVER['HTTP_DNT'] = '1';
track_catalog_view();
unset($_SERVER['HTTP_DNT']);
$_SERVER['HTTP_USER_AGENT'] = 'somebot crawler';
track_catalog_view();
$_SERVER['HTTP_USER_AGENT'] = '';
track_catalog_view();
$_SERVER['HTTP_USER_AGENT'] = 'CI integration browser';
$_SERVER['REQUEST_METHOD'] = 'POST';
track_catalog_view();
$_SERVER['REQUEST_METHOD'] = 'GET';
$secret = '';
track_catalog_view();
checkNoStats($pdo, 'Blocked requests must not create daily records');
$secret = str_repeat('a', 64);

// Admin-IP exclusion records must remain functional and untouched.
analytics_ip_register_login($pdo, 7);
$created = $pdo->query('SELECT created_at FROM visitor_stats_ip_exclusions')->fetchColumn();
analytics_ip_register_login($pdo, 7);
failUnless((int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_ip_exclusions')->fetchColumn() === 1
    && $pdo->query('SELECT created_at FROM visitor_stats_ip_exclusions')->fetchColumn() === $created,
    'Existing IP/admin pair must be an unchanged no-op');
analytics_ip_register_login($pdo, 8);
failUnless((int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_ip_exclusions')->fetchColumn() === 2,
    'Shared IP retains both admin IDs');
failUnless(analytics_ip_key('::ffff:192.0.2.2') === analytics_ip_key('192.0.2.2'),
    'Mapped IPv4 normalizes identically');
failUnless(analytics_ip_key('2001:db8::1') === analytics_ip_key('2001:0db8:0:0:0:0:0:1'),
    'IPv6 alternate spelling normalizes identically');
$_SERVER['REMOTE_ADDR'] = '::ffff:192.0.2.2';
track_catalog_view();
track_catalog_view(7);
checkNoStats($pdo, 'Excluded admin IP must not record home or lot views');

analytics_ip_set_exclusion($pdo, false);
foreach (explode(';', (string)$ipSql) as $sql) if (trim($sql) !== '') $pdo->exec($sql);
failUnless(!analytics_ip_exclusion_enabled($pdo)
    && (int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_ip_exclusions')->fetchColumn() === 2,
    'Reapplying migration must preserve disabled switch and registered pairs');
track_catalog_view();
checkNoStats($pdo, 'Disabling admin-IP exclusion cannot bypass Belgium-only policy');
analytics_ip_set_exclusion($pdo, true);
track_catalog_view();
checkNoStats($pdo, 'Re-enabling admin-IP exclusion must keep unknown origins excluded');

// A spoofed forwarding header may never override trusted REMOTE_ADDR.
$_SERVER['REMOTE_ADDR'] = '192.0.2.44';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '81.82.83.84';
track_catalog_view();
track_catalog_view(7);
checkNoStats($pdo, 'Untrusted forwarded IP must not admit unknown-origin traffic');
$pdo->exec('DELETE FROM visitor_stats_ip_exclusions');
echo "PASS isolated MySQL country-fail-closed tracking and admin IP exclusion contracts\n";
