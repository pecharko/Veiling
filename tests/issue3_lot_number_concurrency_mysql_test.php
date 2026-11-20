<?php
declare(strict_types=1);
/**
 * Issue #3: two overlapping workers against disposable MySQL.
 * Exercises the editor's DB lock, transaction, and permanent number reservation.
 * The separate kavel_http_integration.php covers HTTP duplicate-error feedback.
 */
if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('CI_DB_HOST') !== '127.0.0.1'
    || getenv('CI_DB_NAME') !== 'auction_test') {
    throw new RuntimeException('Isolated GitHub CI database required');
}
if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
    throw new RuntimeException('pcntl required');
}
require dirname(__DIR__) . '/private/catalog_mutex.php';
$dsn = 'mysql:host=127.0.0.1;dbname=auction_test;charset=utf8mb4';
$user = (string)getenv('CI_DB_USER');
$pass = (string)getenv('CI_DB_PASSWORD');
$newDb = static fn(): PDO => new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 5,
]);
$number = 987654;
$verify = $newDb();
foreach (['lots', 'issued_lot_numbers'] as $table) {
    $stmt = $verify->prepare("SELECT COUNT(*) FROM {$table} WHERE lot_number=?");
    $stmt->execute([$number]);
    if ((int)$stmt->fetchColumn() !== 0) {
        throw new RuntimeException('CI fixture lot number is already used');
    }
}
unset($verify); // Forked workers must not inherit an open database connection.
$gate = tempnam(sys_get_temp_dir(), 'lot-race-');
if ($gate === false) throw new RuntimeException('Cannot create race barrier');
unlink($gate);
$pids = [];
try {
    for ($n = 0; $n < 2; ++$n) {
        $pid = pcntl_fork();
        if ($pid < 0) throw new RuntimeException('Could not fork worker');
        if ($pid === 0) {
            $deadline = microtime(true) + 8;
            while (!is_file($gate)) {
                if (microtime(true) > $deadline) exit(20);
                usleep(10000);
            }
            $db = null;
            $locked = false;
            $code = 22;
            try {
                $db = $newDb();
                catalog_data_lock($db, 5);
                $locked = true;
                $db->beginTransaction();
                $db->prepare('INSERT INTO issued_lot_numbers (lot_number) VALUES (?)')
                   ->execute([$number]);
                $db->prepare("INSERT INTO lots (lot_number,title,description,details,status) VALUES (?,'Race fixture','','','draft')")
                   ->execute([$number]);
                $db->commit();
                $code = 0;
            } catch (PDOException $e) {
                if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
                $code = $e->getCode() === '23000' && (int)($e->errorInfo[1] ?? 0) === 1062 ? 1 : 21;
            } catch (Throwable $e) {
                if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
                $code = 22;
            } finally {
                if ($locked && $db instanceof PDO) {
                    try { catalog_data_unlock($db); } catch (Throwable $e) { $code = 23; }
                }
            }
            exit($code);
        }
        $pids[] = $pid;
    }
    if (!touch($gate)) throw new RuntimeException('Cannot release race barrier');
    $results = [];
    foreach ($pids as $pid) {
        if (pcntl_waitpid($pid, $status) !== $pid) throw new RuntimeException('Worker wait failed');
        $results[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 99;
    }
    $pids = [];
    sort($results);
    $db = $newDb();
    $counts = [];
    foreach (['lots', 'issued_lot_numbers'] as $table) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE lot_number=?");
        $stmt->execute([$number]);
        $counts[$table] = (int)$stmt->fetchColumn();
    }
    if ($results !== [0, 1] || $counts !== ['lots' => 1, 'issued_lot_numbers' => 1]) {
        throw new RuntimeException('Exactly one lot and one reservation required');
    }
    // Deleting the lot must not release the permanent number reservation.
    $db->prepare('DELETE FROM lots WHERE lot_number=?')->execute([$number]);
    try {
        $db->prepare('INSERT INTO issued_lot_numbers (lot_number) VALUES (?)')->execute([$number]);
        throw new RuntimeException('Deleted number unexpectedly reused');
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000' || (int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
    }
    echo "PASS issue #3: two concurrent DB workers, one lot, one reservation, reuse blocked\n";
} finally {
    @touch($gate);
    foreach ($pids as $pid) pcntl_waitpid($pid, $status);
    @unlink($gate);
    // Cleanup is strictly limited to the disposable CI fixture.
    $db = $newDb();
    $db->prepare('DELETE FROM lots WHERE lot_number=?')->execute([$number]);
    $db->prepare('DELETE FROM issued_lot_numbers WHERE lot_number=?')->execute([$number]);
}
