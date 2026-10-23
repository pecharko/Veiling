<?php
declare(strict_types=1);
// This test deliberately refuses all DSNs except a disposable localhost CI database.
$dsn = (string)(getenv('ANALYTICS_TEST_DSN') ?: '');
if (!str_starts_with($dsn, 'mysql:host=127.0.0.1;port=3306;dbname=visitor_stats_ci;') || !str_contains($dsn, 'charset=utf8mb4')) {
    fwrite(STDERR, "STOP: only disposable localhost CI MySQL is permitted\n");
    exit(2);
}
$pdo = new PDO($dsn, (string)getenv('ANALYTICS_TEST_USER'), (string)getenv('ANALYTICS_TEST_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DROP TABLE IF EXISTS visitor_stats_daily');
$pdo->exec('CREATE TABLE visitor_stats_daily (visit_date DATE NOT NULL, visitor_id BINARY(32) NOT NULL, page_kind VARCHAR(8) NOT NULL, lot_id BIGINT UNSIGNED NOT NULL DEFAULT 0, views INT UNSIGNED NOT NULL DEFAULT 1, PRIMARY KEY(visit_date, visitor_id, page_kind, lot_id)) ENGINE=InnoDB');
$secret = str_repeat('a', 64);
function cfg(string $key): ?string { global $secret; return $key === 'analytics_secret' ? $secret : null; }
function db(): PDO { global $pdo; return $pdo; }
require dirname(__DIR__) . '/private/visitor_stats.php';
function failUnless(bool $condition, string $description): void { if (!$condition) throw new RuntimeException($description); }
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
$_SERVER['HTTP_USER_AGENT'] = 'CI integration browser';
unset($_SERVER['HTTP_DNT']);
track_catalog_view();
track_catalog_view();
failUnless((int)$pdo->query("SELECT COALESCE(SUM(views),0) FROM visitor_stats_daily")->fetchColumn() === 2, 'Repeat page visit not counted');
track_catalog_view(7);
failUnless((int)$pdo->query("SELECT COUNT(*) FROM visitor_stats_daily")->fetchColumn() === 2, 'Lot view not separated');
$_SERVER['REMOTE_ADDR'] = '192.0.2.2';
track_catalog_view();
failUnless((int)$pdo->query("SELECT COUNT(DISTINCT HEX(visitor_id)) FROM visitor_stats_daily")->fetchColumn() === 2, 'Visitors not separated');
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
failUnless((int)$pdo->query("SELECT COALESCE(SUM(views),0) FROM visitor_stats_daily")->fetchColumn() === 4, 'Skipped visits unexpectedly counted');
echo "PASS isolated MySQL visitor writes and suppression contracts\n";
