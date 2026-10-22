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
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
$r = archive_visitor_stats_before($pdo,$cutoff);
check((bool)$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) === false,
    'Archive unexpectedly altered caller PDO buffering configuration');
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
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
// Two independent connections: an uncommitted historical row update must
// not allow archival to commit partial totals or remove the locked source.
$lockDay = gmdate('Y-m-d', strtotime('-62 days'));
$add->execute([$lockDay, $a, 'home', 0, 2]);
$other = new PDO($dsn, (string)getenv('ANALYTICS_TEST_USER'), (string)getenv('ANALYTICS_TEST_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
// The earlier fork invalidated inherited MySQL sockets; use a fresh peer session.
$other = new PDO($dsn, (string)getenv('ANALYTICS_TEST_USER'), (string)getenv('ANALYTICS_TEST_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$other->beginTransaction();
try {
    $lock = $other->prepare('UPDATE visitor_stats_daily SET views=views+1 WHERE visit_date=?');
    $lock->execute([$lockDay]);
    check($lock->rowCount() === 1, 'Unable to establish concurrent record lock');
    $blocked = false;
    try {
        archive_visitor_stats_before($pdo, $cutoff);
    } catch (PDOException $ex) {
        $blocked = in_array((string)$ex->getCode(), ['HY000', '40001'], true);
    }
    check($blocked, 'Archive should fail closed when another connection locks historic rows');
    check(!$pdo->inTransaction(), 'Archive transaction remained open after lock timeout');
    $other->rollBack();
} finally {
    if ($other->inTransaction()) $other->rollBack();
}
$lockCount = $pdo->prepare('SELECT COUNT(*) FROM visitor_stats_daily WHERE visit_date=?');
$lockCount->execute([$lockDay]);
check((int)$lockCount->fetchColumn() === 1, 'Concurrent conflict removed historic source');
$lockAggregate = $pdo->prepare('SELECT COUNT(*) FROM visitor_stats_day_totals WHERE visit_date=?');
$lockAggregate->execute([$lockDay]);
check((int)$lockAggregate->fetchColumn() === 0, 'Concurrent conflict committed archive aggregate');
$afterLock = archive_visitor_stats_before($pdo, $cutoff);
check($afterLock['rows'] === 1 && $afterLock['views'] === 2, 'Archive did not recover after lock release');
// Two sessions: verify historical date-range gap locks prevent an INSERT
// while an archiving transaction holds the matching range. This tests lock
// primitives; it is NOT by itself proof that the archiver obtains them.
$phantomDay = gmdate('Y-m-d', strtotime('-70 days'));
$add->execute([$phantomDay, $a, 'home', 0, 1]);
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$pdo->beginTransaction();
try {
    // Intentionally use the index-leading visit_date and locking read.
    $rangeLock = $pdo->prepare('SELECT visit_date,visitor_id FROM visitor_stats_daily WHERE visit_date=? FOR UPDATE');
    $rangeLock->execute([$phantomDay]);
    $rangeLock->fetchAll(PDO::FETCH_ASSOC);
    $rangeLock->closeCursor();
    $other->exec('SET SESSION innodb_lock_wait_timeout=1');
    $phantomInsert = $other->prepare("INSERT INTO visitor_stats_daily (visit_date,visitor_id,page_kind,lot_id,views) VALUES (?,UNHEX(?),'home',0,1)");
    $insertBlocked = false;
    try {
        $phantomInsert->execute([$phantomDay, str_repeat('ee', 32)]);
    } catch (PDOException $ex) {
        $insertBlocked = in_array((string)$ex->errorInfo[1], ['1205','1213'], true);
    }
    check($insertBlocked, 'Historic date-range locking read allowed phantom INSERT');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$phantomResult = archive_visitor_stats_before($pdo, $cutoff);
check($phantomResult['rows'] === 1 && $phantomResult['views'] === 1, 'Archive failed after range-lock release');
// A previously empty historical date must also be protected by a range lock.
// Probe the InnoDB index-gap primitive separately; production archive must
// still prove that it actually holds equivalent locks during date discovery.
$emptyHistoric = gmdate('Y-m-d', strtotime('-73 days'));
$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$pdo->beginTransaction();
try {
    $emptyRange = $pdo->prepare('SELECT visit_date, visitor_id FROM visitor_stats_daily FORCE INDEX(PRIMARY) WHERE visit_date=? FOR UPDATE');
    $emptyRange->execute([$emptyHistoric]);
    check($emptyRange->fetch(PDO::FETCH_ASSOC) === false, 'Expected previously empty historic date');
    $emptyRange->closeCursor();
    $other->exec('SET SESSION innodb_lock_wait_timeout=1');
    $emptyInsert = $other->prepare("INSERT INTO visitor_stats_daily (visit_date,visitor_id,page_kind,lot_id,views) VALUES (?,UNHEX(?),'home',0,1)");
    $emptyBlocked = false;
    try { $emptyInsert->execute([$emptyHistoric, str_repeat('dd',32)]); }
    catch (PDOException $ex) {
        $emptyBlocked = in_array((string)($ex->errorInfo[1] ?? ''), ['1205','1213'], true);
    }
    check($emptyBlocked, 'Empty historic day range did not block phantom insertion');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
// End-to-end archive race: a second connection inserts a previously absent
// historical day but has NOT committed. Archiving must never commit an
// incomplete history while that insert remains in flight.
$raceDay = gmdate('Y-m-d', strtotime('-80 days'));
$pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
$other->beginTransaction();
try {
    $pending = $other->prepare("INSERT INTO visitor_stats_daily (visit_date,visitor_id,page_kind,lot_id,views) VALUES (?,UNHEX(?),'home',0,1)");
    $pending->execute([$raceDay, str_repeat('cc', 32)]);
    $blocked = false;
    try {
        archive_visitor_stats_before($pdo, $cutoff);
    } catch (PDOException $ex) {
        $blocked = in_array((string)($ex->errorInfo[1] ?? ''), ['1205', '1213'], true);
    }
    check($blocked, 'Archive completed while an uncommitted new historical date existed');
    check(!$pdo->inTransaction(), 'Archive left open transaction after concurrent INSERT');
    $existingArchive = $pdo->prepare('SELECT COUNT(*) FROM visitor_stats_day_totals WHERE visit_date=?');
    $existingArchive->execute([$raceDay]);
    check((int)$existingArchive->fetchColumn() === 0, 'Archive persisted phantom day aggregate despite rollback');
    $other->commit();
} finally {
    if ($other->inTransaction()) $other->rollBack();
}
$raceArchive = archive_visitor_stats_before($pdo, $cutoff);
check($raceArchive['rows'] === 1 && $raceArchive['views'] === 1, 'Retry after concurrent historical INSERT failed');
// Bounded-client-memory load regression, isolated MySQL only. No wall-clock
// assertion: shared CI runners have variable scheduling and DB speed.
$loadDate = gmdate('Y-m-d', strtotime('-91 days'));
$loadRows = 15000;
$loadInsert = $pdo->prepare("INSERT INTO visitor_stats_daily (visit_date,visitor_id,page_kind,lot_id,views) VALUES (?,UNHEX(?),'home',0,1)");
for ($n = 0; $n < $loadRows; $n++) {
    $loadInsert->execute([$loadDate, str_pad(dechex(50000 + $n), 64, '0', STR_PAD_LEFT)]);
}
// Read per-session MySQL counters to distinguish database-side work
// from PHP memory. These counters are diagnostic, not hard CI thresholds.
$serverCounters = static function (PDO $connection): array {
    $data = [];
    foreach ($connection->query("SHOW SESSION STATUS WHERE Variable_name IN ('Created_tmp_disk_tables','Created_tmp_tables','Handler_read_next')") as $row) {
        $data[(string)$row['Variable_name']] = (int)$row['Value'];
    }
    return $data;
};
$mysqlBefore = $serverCounters($pdo);
// An oversized manual operation must fail before any destructive SQL.
for ($n = 0; $n < 5001; $n++) {
    $loadInsert->execute([$loadDate, str_pad(dechex(70000 + $n), 64, '0', STR_PAD_LEFT)]);
}
$rejectedLarge = false;
try {
    archive_visitor_stats_before($pdo, $cutoff);
} catch (RuntimeException $exception) {
    $rejectedLarge = str_contains($exception->getMessage(), 'safety limit');
}
check($rejectedLarge && !$pdo->inTransaction(), 'Oversized archival prefix must fail closed');
$oversizedCount = $pdo->prepare('SELECT COUNT(*) FROM visitor_stats_daily WHERE visit_date=?');
$oversizedCount->execute([$loadDate]);
check((int)$oversizedCount->fetchColumn() === 20001, 'Oversize guard changed source data');
$existingTotal = $pdo->prepare('SELECT COUNT(*) FROM visitor_stats_day_totals WHERE visit_date=?');
$existingTotal->execute([$loadDate]);
check((int)$existingTotal->fetchColumn() === 0, 'Oversize guard wrote aggregate data');
$pdo->prepare('DELETE FROM visitor_stats_daily WHERE visit_date=? AND visitor_id >= UNHEX(?)')
    ->execute([$loadDate, str_pad(dechex(70000), 64, '0', STR_PAD_LEFT)]);
$memoryBefore = memory_get_peak_usage(true);
$loadStarted = microtime(true);
$loadResult = archive_visitor_stats_before($pdo, $cutoff);
$loadSeconds = microtime(true) - $loadStarted;
$mysqlAfter = $serverCounters($pdo);
$memoryAfter = memory_get_peak_usage(true);
fwrite(STDOUT, sprintf("Archive MySQL session counters: temp tables +%d, disk temp tables +%d, index rows read +%d\\n",
    ($mysqlAfter['Created_tmp_tables'] ?? 0) - ($mysqlBefore['Created_tmp_tables'] ?? 0),
    ($mysqlAfter['Created_tmp_disk_tables'] ?? 0) - ($mysqlBefore['Created_tmp_disk_tables'] ?? 0),
    ($mysqlAfter['Handler_read_next'] ?? 0) - ($mysqlBefore['Handler_read_next'] ?? 0)));
check($loadResult['rows'] === $loadRows && $loadResult['views'] === $loadRows
    && $loadResult['days'] === 1, 'High-volume daily archive failed');
$loadCheck = $pdo->prepare('SELECT visitor_days, views FROM visitor_stats_day_totals WHERE visit_date=?');
$loadCheck->execute([$loadDate]);
$loadTotals = $loadCheck->fetch(PDO::FETCH_ASSOC);
check((int)$loadTotals['visitor_days'] === $loadRows
    && (int)$loadTotals['views'] === $loadRows, 'High-volume distinct totals mismatch');
check($memoryAfter < 64 * 1024 * 1024, 'High-volume archive exceeded PHP peak memory safety envelope');
fwrite(STDOUT, sprintf("Archive load regression: %d rows, %.3fs, peak PHP memory %.1f MiB (before %.1f MiB)\\n",
    $loadRows, $loadSeconds, $memoryAfter / 1048576, $memoryBefore / 1048576));
// Deterministic conflicting row-lock regression: the archive must fail closed
// while another connection owns an uncommitted lock, then recover correctly.
$conflictDay = gmdate('Y-m-d', strtotime('-96 days'));
$add->execute([$conflictDay, $a, 'lot', 31, 9]);
$pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
$other->beginTransaction();
try {
    $held = $other->prepare('SELECT visitor_id FROM visitor_stats_daily WHERE visit_date=? FOR UPDATE');
    $held->execute([$conflictDay]);
    check($held->fetch(PDO::FETCH_ASSOC) !== false, 'Could not acquire competing row lock');
    $held->closeCursor();
    $conflicted = false;
    try {
        archive_visitor_stats_before($pdo, $cutoff);
    } catch (PDOException $exception) {
        $conflicted = in_array((string)($exception->errorInfo[1] ?? ''), ['1205','1213'], true);
    }
    check($conflicted, 'Archive committed while competing historic row lock was held');
    check(!$pdo->inTransaction(), 'Archive left transaction open on row-lock conflict');
    $dayCount = $pdo->prepare('SELECT COUNT(*) FROM visitor_stats_day_totals WHERE visit_date=?');
    $dayCount->execute([$conflictDay]);
    check((int)$dayCount->fetchColumn() === 0, 'Archive inserted aggregate despite failed lock');
} finally {
    if ($other->inTransaction()) $other->rollBack();
}
$conflictRecovery = archive_visitor_stats_before($pdo, $cutoff);
check($conflictRecovery['rows'] === 1 && $conflictRecovery['views'] === 9, 'Archive failed after historic row lock was released');
// Deterministic two-session cyclic deadlock, isolated DB only.
// Each transaction owns a different row, then requests the other's row.
// Fork a child to allow genuinely simultaneous blocking requests. A deadlock
// victim must receive MySQL 1213; both transactions are rolled back.
check(function_exists('pcntl_fork') && function_exists('stream_socket_pair'),
    'Isolated CI must support fork and socket IPC for mandatory deadlock regression');
{
    $deadA = gmdate('Y-m-d', strtotime('-100 days'));
    $deadB = gmdate('Y-m-d', strtotime('-101 days'));
    $add->execute([$deadA, $a, 'home', 0, 1]);
    $add->execute([$deadB, $a, 'home', 0, 1]);
    $channels = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    check(is_array($channels), 'Deadlock test could not allocate IPC');
    $pid = pcntl_fork();
    check($pid >= 0, 'Deadlock test failed to fork');
    if ($pid === 0) {
        fclose($channels[0]);
        $childDb = new PDO($dsn, (string)getenv('ANALYTICS_TEST_USER'), (string)getenv('ANALYTICS_TEST_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $childDb->exec('SET SESSION innodb_lock_wait_timeout=5');
        $childDb->beginTransaction();
        try {
            $lock = $childDb->prepare('SELECT visitor_id FROM visitor_stats_daily WHERE visit_date=? FOR UPDATE');
            $lock->execute([$deadB]); $lock->closeCursor();
            fwrite($channels[1], "ready\n");
            fgets($channels[1]);
            $lock->execute([$deadA]); $lock->closeCursor();
            fwrite($channels[1], "ok\n");
        } catch (PDOException $ex) {
            fwrite($channels[1], "error:" . (string)($ex->errorInfo[1] ?? '') . "\n");
        } finally {
            if ($childDb->inTransaction()) $childDb->rollBack();
            fclose($channels[1]);
        }
        exit(0);
    }
    fclose($channels[1]);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
    $pdo->beginTransaction();
    try {
        $mainLock = $pdo->prepare('SELECT visitor_id FROM visitor_stats_daily WHERE visit_date=? FOR UPDATE');
        $mainLock->execute([$deadA]); $mainLock->closeCursor();
        check(trim((string)fgets($channels[0])) === 'ready', 'Child did not lock second date');
        fwrite($channels[0], "go\n");
        $parentError = '';
        try { $mainLock->execute([$deadB]); $mainLock->closeCursor(); }
        catch (PDOException $ex) { $parentError = (string)($ex->errorInfo[1] ?? ''); }
        $childStatus = trim((string)fgets($channels[0]));
        check($parentError === '1213' || $childStatus === 'error:1213',
            'Cyclic lock conflict did not produce deadlock victim');
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fclose($channels[0]);
        pcntl_waitpid($pid, $status);
    }
    // Forked child inherited the parent's MySQL socket; closing the inherited
    // descriptor during child exit can invalidate that PDO connection.
    // Use a fresh connection for subsequent isolated assertions.
    $pdo = new PDO($dsn, (string)getenv('ANALYTICS_TEST_USER'), (string)getenv('ANALYTICS_TEST_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $afterCycle = archive_visitor_stats_before($pdo, $cutoff);
    check($afterCycle['rows'] === 2, 'Archive did not recover from synthetic deadlock');
    fwrite(STDOUT, "Cyclic InnoDB deadlock regression passed: victim 1213 and archive recovery\\n");
}
// Experimental day-scoped prototype: one committed day per manual call.
require dirname(__DIR__) . '/private/visitor_stats_archive_one_day_experimental.php';
$batchFirst = gmdate('Y-m-d', strtotime('-105 days'));
$batchSecond = gmdate('Y-m-d', strtotime('-104 days'));
// Re-prepare after the fork: $add belongs to the closed original PDO connection.
$add = $pdo->prepare('INSERT INTO visitor_stats_daily (visit_date,visitor_id,page_kind,lot_id,views) VALUES (?,UNHEX(?),?,?,?)');
$add->execute([$batchFirst,$a,'home',0,3]);
$add->execute([$batchSecond,$b,'lot',42,4]);
$one = archive_visitor_stats_one_day($pdo, $cutoff);
check($one['days'] === 1 && $one['rows'] === 1 && $one['views'] === 3,
    'One-day archive failed to commit oldest UTC day');
$remaining = $pdo->prepare('SELECT COUNT(*) FROM visitor_stats_daily WHERE visit_date=?');
$remaining->execute([$batchSecond]);
check((int)$remaining->fetchColumn() === 1, 'One-day prototype consumed more than one UTC day');
$two = archive_visitor_stats_one_day($pdo, $cutoff);
check($two['days'] === 1 && $two['views'] === 4, 'Second one-day archive did not resume');
check(archive_visitor_stats_one_day($pdo, $cutoff)['days'] === 0, 'One-day archive was not idempotent');
// Competing writer regression for the actual experimental day-scoped function.
$batchLocked = gmdate('Y-m-d', strtotime('-106 days'));
$add->execute([$batchLocked,$a,'lot',57,6]);
$pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
// A fresh peer PDO is needed after the earlier child process exits.
$other = new PDO($dsn, (string)getenv('ANALYTICS_TEST_USER'), (string)getenv('ANALYTICS_TEST_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$other->beginTransaction();
try {
    $heldBatch = $other->prepare('SELECT visitor_id FROM visitor_stats_daily WHERE visit_date=? FOR UPDATE');
    $heldBatch->execute([$batchLocked]);
    check($heldBatch->fetch(PDO::FETCH_ASSOC) !== false, 'Could not lock one-day source row');
    $heldBatch->closeCursor();
    $rejectedBatch = false;
    try {
        archive_visitor_stats_one_day($pdo, $cutoff);
    } catch (PDOException $ex) {
        $rejectedBatch = in_array((string)($ex->errorInfo[1] ?? ''), ['1205','1213'], true);
    }
    check($rejectedBatch && !$pdo->inTransaction(), 'One-day archive must roll back on competing row lock');
    $batchAggregate = $pdo->prepare('SELECT COUNT(*) FROM visitor_stats_day_totals WHERE visit_date=?');
    $batchAggregate->execute([$batchLocked]);
    check((int)$batchAggregate->fetchColumn() === 0, 'One-day archive persisted aggregate despite lock failure');
} finally {
    if ($other->inTransaction()) $other->rollBack();
}
$recoveredBatch = archive_visitor_stats_one_day($pdo, $cutoff);
check($recoveredBatch['rows'] === 1 && $recoveredBatch['views'] === 6,
    'One-day archive did not resume after competing lock released');
// Two independent manual archive requests targeting the same oldest historical UTC day.
// The second must fail closed under the first request's row lock; once released,
// only one daily/lot aggregate may exist and a retry must be idempotent.
$doubleDay = gmdate('Y-m-d', strtotime('-107 days'));
$add->execute([$doubleDay, $a, 'lot', 58, 9]);
$competitor = new PDO($dsn, (string)getenv('ANALYTICS_TEST_USER'), (string)getenv('ANALYTICS_TEST_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$competitor->exec('SET SESSION innodb_lock_wait_timeout=1');
$pdo->beginTransaction();
try {
    $held = $pdo->prepare('SELECT visitor_id FROM visitor_stats_daily WHERE visit_date=? FOR UPDATE');
    $held->execute([$doubleDay]);
    check($held->fetch(PDO::FETCH_ASSOC) !== false, 'Cannot reserve oldest archive date');
    $held->closeCursor();
    $contended = false;
    try {
        archive_visitor_stats_one_day($competitor, $cutoff);
    } catch (PDOException $ex) {
        $contended = in_array((string)($ex->errorInfo[1] ?? ''), ['1205','1213'], true);
    }
    check($contended && !$competitor->inTransaction(), 'Second archive request must fail closed under first lock');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$winner = archive_visitor_stats_one_day($pdo, $cutoff);
check($winner['days'] === 1 && $winner['rows'] === 1 && $winner['views'] === 9,
    'First archival request after lock release must commit exactly once');
check(archive_visitor_stats_one_day($competitor, $cutoff)['days'] === 0,
    'Second archival request must be idempotent after first commit');
$uniqueDay = $pdo->prepare('SELECT COUNT(*) FROM visitor_stats_day_totals WHERE visit_date=?');
$uniqueDay->execute([$doubleDay]);
check((int)$uniqueDay->fetchColumn() === 1, 'Double archive created duplicate daily aggregates');
$uniqueLot = $pdo->prepare('SELECT COUNT(*) FROM visitor_stats_lot_totals WHERE visit_date=? AND lot_id=58');
$uniqueLot->execute([$doubleDay]);
check((int)$uniqueLot->fetchColumn() === 1, 'Double archive created duplicate lot aggregates');
// A concurrent historical insert on the same UTC day must not bypass the
// archive row lock; after rollback, the insert and archive must both recover.
$phantomDay = gmdate('Y-m-d', strtotime('-108 days'));
$add->execute([$phantomDay, $a, 'home', 0, 2]);
$writer = new PDO($dsn, (string)getenv('ANALYTICS_TEST_USER'), (string)getenv('ANALYTICS_TEST_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$writer->exec('SET SESSION innodb_lock_wait_timeout=1');
$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$pdo->beginTransaction();
try {
    $lockedDay = $pdo->prepare('SELECT page_kind FROM visitor_stats_daily FORCE INDEX(PRIMARY) WHERE visit_date=? FOR UPDATE');
    $lockedDay->execute([$phantomDay]);
    check($lockedDay->fetch(PDO::FETCH_ASSOC) !== false, 'Failed to lock historical day');
    $lockedDay->closeCursor();
    $blocked = false;
    try {
        $insertDuringArchive = $writer->prepare('INSERT INTO visitor_stats_daily (visit_date,visitor_id,page_kind,lot_id,views) VALUES (?,UNHEX(?),?,?,?)');
        $insertDuringArchive->execute([$phantomDay, $b, 'lot', 77, 5]);
    } catch (PDOException $ex) {
        $blocked = in_array((string)($ex->errorInfo[1] ?? ''), ['1205','1213'], true);
    }
    check($blocked, 'Historical same-day insert bypassed next-key row lock');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$insertDuringArchive = $writer->prepare('INSERT INTO visitor_stats_daily (visit_date,visitor_id,page_kind,lot_id,views) VALUES (?,UNHEX(?),?,?,?)');
$insertDuringArchive->execute([$phantomDay, $b, 'lot', 77, 5]);
$phantomResult = archive_visitor_stats_one_day($pdo, $cutoff);
check($phantomResult['days'] === 1 && $phantomResult['rows'] === 2 && $phantomResult['views'] === 7,
    'Day archive lost newly registered historical visitor after retry');
// A late historic insert may be older than a day already archived.
// Verify that a subsequent manual invocation recovers the older date rather
// than silently skipping it; this is NOT a simultaneous insert interleaving.
$lateLaterDay = gmdate('Y-m-d', strtotime('-109 days'));
$lateOlderDay = gmdate('Y-m-d', strtotime('-110 days'));
$add->execute([$lateLaterDay, $a, 'home', 0, 4]);
$laterResult = archive_visitor_stats_one_day($pdo, $cutoff);
check($laterResult['days'] === 1 && $laterResult['views'] === 4, 'Initial later-day archive failed');
$add->execute([$lateOlderDay, $b, 'lot', 81, 5]);
$olderResult = archive_visitor_stats_one_day($pdo, $cutoff);
check($olderResult['days'] === 1 && $olderResult['rows'] === 1 && $olderResult['views'] === 5,
    'Late older historical day was not found on next manual invocation');
$lateTotals = $pdo->prepare('SELECT COUNT(*) FROM visitor_stats_day_totals WHERE visit_date IN (?,?)');
$lateTotals->execute([$lateOlderDay, $lateLaterDay]);
check((int)$lateTotals->fetchColumn() === 2, 'Late older day or earlier day was not aggregated exactly once');
$lateLot = $pdo->prepare('SELECT views FROM visitor_stats_lot_totals WHERE visit_date=? AND lot_id=81');
$lateLot->execute([$lateOlderDay]);
check((int)$lateLot->fetchColumn() === 5, 'Late older lot aggregate incorrect');
fwrite(STDOUT, "Late older UTC-day registration recovery passed across two manual invocations\\n");
fwrite(STDOUT, "One-day concurrent historical insert regression passed: blocked insert and safe retry\\n");
fwrite(STDOUT, "Two competing manual one-day archive requests passed: lock refusal, retry, unique aggregates\\n");
fwrite(STDOUT, "Experimental one-day concurrent row-lock regression passed\\n");
fwrite(STDOUT, "Experimental one-day archival passed: isolated commits and resume\\n");
echo "MySQL archive integration passed: accuracy, idempotency, rollback, malformed-data protection, recovery, recent-day protection\\n";
