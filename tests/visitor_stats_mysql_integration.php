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
// SQL-side aggregation must handle more than the old 1000-record guard,
// without holding visitor identifier sets in PHP.
$largeDay = gmdate('Y-m-d', strtotime('-49 days'));
$largeAdd = $pdo->prepare("INSERT INTO visitor_stats_daily (visit_date,visitor_id,page_kind,lot_id,views) VALUES (?,UNHEX(?),'home',0,1)");
for ($n = 0; $n < 1001; $n++) {
    $largeAdd->execute([$largeDay, str_pad(dechex($n + 1), 64, '0', STR_PAD_LEFT)]);
}
$large = archive_visitor_stats_before($pdo, $cutoff);
check($large['rows'] === 1001 && $large['days'] === 1 && $large['views'] === 1001, 'SQL aggregation failed for large daily volume');
$largeTotals = $pdo->prepare('SELECT visitor_days,views FROM visitor_stats_day_totals WHERE visit_date=?');
$largeTotals->execute([$largeDay]);
$largeRow = $largeTotals->fetch(PDO::FETCH_ASSOC);
check((int)$largeRow['visitor_days'] === 1001 && (int)$largeRow['views'] === 1001, 'Large daily unique visitors mismatch');
check((int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_daily')->fetchColumn() === 1, 'Recent visit lost in large run');
// Multi-day atomicity: a later-day conflict must roll back earlier-day aggregates.
$firstDay = gmdate('Y-m-d', strtotime('-60 days'));
$secondDay = gmdate('Y-m-d', strtotime('-59 days'));
$add->execute([$firstDay, $a, 'home', 0, 7]);
$add->execute([$secondDay, $b, 'lot', 12, 5]);
$pdo->prepare('INSERT INTO visitor_stats_day_totals (visit_date,visitor_days,views,lot_views) VALUES (?,1,99,0)')->execute([$secondDay]);
$beforeCount = (int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_daily')->fetchColumn();
$multiFailed = false;
try { archive_visitor_stats_before($pdo, $cutoff); } catch (PDOException $ex) { $multiFailed = true; }
check($multiFailed, 'Later-day duplicate must stop multi-day archive');
check((int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_daily')->fetchColumn() === $beforeCount, 'Multi-day rollback removed source records');
$firstArchive = $pdo->prepare('SELECT COUNT(*) FROM visitor_stats_day_totals WHERE visit_date=?');
$firstArchive->execute([$firstDay]);
check((int)$firstArchive->fetchColumn() === 0, 'Earlier-day aggregate survived failed multi-day transaction');
$pdo->prepare('DELETE FROM visitor_stats_day_totals WHERE visit_date=?')->execute([$secondDay]);
$multiRecovered = archive_visitor_stats_before($pdo, $cutoff);
check($multiRecovered['rows'] === 2 && $multiRecovered['views'] === 12 && $multiRecovered['days'] === 2, 'Multi-day recovery incorrect');
check((int)$pdo->query('SELECT COUNT(*) FROM visitor_stats_daily')->fetchColumn() === 1, 'Recent record removed in multi-day recovery');
echo "MySQL archive integration passed: accuracy, idempotency, rollback, malformed-data protection, recovery, recent-day protection\\n";
