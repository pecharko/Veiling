<?php
declare(strict_types=1);
/** Isolated MySQL integration: uses ONLY fresh temporary test database supplied in CI. */
require dirname(__DIR__) . '/private/visitor_stats_archive.php';
$dsn = (string)(getenv('ANALYTICS_TEST_DSN') ?: '');
if (!str_starts_with($dsn, 'mysql:host=127.0.0.1;port=3306;dbname=visitor_stats_ci;') || !str_contains($dsn, 'charset=utf8mb4')) {
    fwrite(STDERR, "Refusing non-isolated test DSN\n"); exit(2);
}
$pdo = new PDO($dsn, (string)getenv('ANALYTICS_TEST_USER'), (string)getenv('ANALYTICS_TEST_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
function check(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
}
$pdo->exec('CREATE TABLE visitor_stats_daily (visit_date DATE NOT NULL, visitor_id BINARY(32) NOT NULL, page_kind VARCHAR(8) NOT NULL, lot_id BIGINT UNSIGNED NOT NULL DEFAULT 0, views INT UNSIGNED NOT NULL DEFAULT 1, PRIMARY KEY (visit_date, visitor_id, page_kind, lot_id), KEY idx_lot_top (visit_date, page_kind, lot_id)) ENGINE=InnoDB');
$migration = file_get_contents(dirname(__DIR__) . '/database/visitor_stats_aggregates.sql');
if (!is_string($migration)) throw new RuntimeException('Test migration missing');
foreach (explode(';', $migration) as $statement) {
    // Strip SQL-only comment lines before executing individual DDL statements.
    $statement = preg_replace('/^\\s*--[^\\n]*$/m', '', $statement);
    if (trim((string)$statement) !== '') $pdo->exec((string)$statement);
}
$old = gmdate('Y-m-d', strtotime('-45 days'));
$recent = gmdate('Y-m-d', strtotime('-2 days'));
$cutoff = gmdate('Y-m-d', strtotime('-30 days'));
$add = $pdo->prepare('INSERT INTO visitor_stats_daily (visit_date,visitor_id,page_kind,lot_id,views) VALUES (?,UNHEX(?),?,?,?)');
$a = str_repeat('11',32); $b = str_repeat('22',32);
$add->execute([$old,$a,'home',0,2]);
$add->execute([$old,$a,'lot',7,3]);
$add->execute([$old,$b,'lot',7,1]);
$add->execute([$old,$b,'lot',8,4]);
$add->execute([$recent,$a,'home',0,1]);
$r = archive_visitor_stats_before($pdo,$cutoff);
check($r['rows']===4 && $r['days']===1 && $r['views']===10,'Incorrect archival result');
$d = $pdo->query('SELECT * FROM visitor_stats_day_totals')->fetch();
check((int)$d['visitor_days']===2 && (int)$d['views']===10 && (int)$d['lot_views']===8,'Daily totals mismatch');
$l = $pdo->query('SELECT lot_id,visitor_days,views FROM visitor_stats_lot_totals ORDER BY lot_id')->fetchAll();
check(count($l)===2 && (int)$l[0]['lot_id']===7 && (int)$l[0]['visitor_days']===2 && (int)$l[0]['views']===4,'Lot 7 mismatch');
check((int)$l[1]['lot_id']===8 && (int)$l[1]['visitor_days']===1 && (int)$l[1]['views']===4,'Lot 8 mismatch');
check((int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_daily')->fetchColumn()===1,'Recent row unexpectedly deleted');
check(archive_visitor_stats_before($pdo,$cutoff)['rows']===0,'Idempotent second run should be empty');
$old2 = gmdate('Y-m-d',strtotime('-46 days'));
$add->execute([$old2,$a,'lot',7,5]);
$pdo->prepare('INSERT INTO visitor_stats_day_totals VALUES (?,1,99,99)')->execute([$old2]);
$failed=false;
try { archive_visitor_stats_before($pdo,$cutoff); } catch (PDOException $ex) { $failed=true; }
check($failed,'Conflicting archived day must fail closed');
check((int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_daily')->fetchColumn()===2,'Conflict caused data loss');
check((int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_lot_totals')->fetchColumn()===2,'Conflict altered lot totals');
$failed=false;
try { archive_visitor_stats_before($pdo,gmdate('Y-m-d')); } catch (InvalidArgumentException $ex) { $failed=true; }
check($failed,'Future/active cutoff must be refused');
// A malformed historic row must not delete valid rows or insert partial aggregates.
$pdo->prepare('DELETE FROM visitor_stats_day_totals WHERE visit_date = ?')->execute([$old2]);
$pdo->prepare('DELETE FROM visitor_stats_daily WHERE visit_date = ?')->execute([$old2]);
$invalidDay = gmdate('Y-m-d', strtotime('-48 days'));
$validDay = gmdate('Y-m-d', strtotime('-47 days'));
$add->execute([$validDay, $a, 'home', 0, 3]);
$add->execute([$invalidDay, $b, 'invalid', 0, 1]);
$beforeDetails = (int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_daily')->fetchColumn();
$beforeTotals = (int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_day_totals')->fetchColumn();
$failed = false;
try { archive_visitor_stats_before($pdo, $cutoff); } catch (RuntimeException $ex) { $failed = true; }
check($failed, 'Invalid page kind must fail closed');
check((int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_daily')->fetchColumn() === $beforeDetails, 'Malformed row caused source data loss');
check((int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_day_totals')->fetchColumn() === $beforeTotals, 'Malformed row caused partial daily aggregates');
$pdo->prepare('DELETE FROM visitor_stats_daily WHERE visit_date = ?')->execute([$invalidDay]);
$recovered = archive_visitor_stats_before($pdo, $cutoff);
check($recovered['rows'] === 1 && $recovered['views'] === 3, 'Valid archive after malformed rollback failed');
check((int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_daily')->fetchColumn() === 1, 'Recent visit not preserved after recovery');
echo "MySQL archive integration passed: accuracy, idempotency, rollback, malformed-data protection, recovery, recent-day protection\\n";
