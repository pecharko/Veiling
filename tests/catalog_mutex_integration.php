<?php
declare(strict_types=1);

/** T-051: MySQL-wide serialization of admin writes, snapshots and restore. */
$root = dirname(__DIR__);
if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('CI_SNAPSHOT_TEST') !== 'yes'
    || getenv('CI_DB_HOST') !== '127.0.0.1'
    || getenv('CI_DB_NAME') !== 'auction_test') {
    throw new RuntimeException('T-051 requires disposable GitHub Actions/MySQL fixtures.');
}
require $root . '/private/bootstrap.php';
require $root . '/private/catalog_mutex.php';
require $root . '/private/snapshots.php';
$pdo = db();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'auction_test') {
    throw new RuntimeException('Refusing to run against an unexpected MySQL database.');
}
$cfg = cfg('database');
$peer = new PDO(
    'mysql:host=' . $cfg['host'] . ';port=' . (int)($cfg['port'] ?? 3306)
       . ';dbname=' . $cfg['name'] . ';charset=utf8mb4',
    $cfg['user'], $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_EMULATE_PREPARES => false]
);
if ($peer->query('SELECT DATABASE()')->fetchColumn() !== 'auction_test') {
    throw new RuntimeException('Unexpected competing MySQL connection.');
}

$passed = 0;
function gate_verify(bool $ok, string $message): void {
    global $passed;
    if (!$ok) throw new RuntimeException('FAILED: ' . $message);
    $passed++;
    echo "PASS T-051: {$message}\n";
}
function gate_blocked(PDO $peer, string $message): void {
    try {
        catalog_data_lock($peer, 0);
    } catch (RuntimeException $e) {
        gate_verify(str_contains($e->getMessage(), 'andere bewerking'), $message);
        return;
    }
    catalog_data_unlock($peer);
    gate_verify(false, $message . ' (unexpectedly acquired lock)');
}
$ownerLocked = false;
$secondOwned = false;
$created = null;
try {
    catalog_data_lock($pdo, 0);
    $ownerLocked = true;
    gate_blocked($peer, 'a competing PHP/MySQL connection cannot enter a write during snapshot');

    catalog_data_lock($pdo, 0);
    $secondOwned = true;
    catalog_data_unlock($pdo);
    $secondOwned = false;
    gate_blocked($peer, 'nested same-session GET_LOCK requires a balanced release');

    // The whole snapshot and subsequent restore may be nested inside the
    // protective outer lock used by the admin safety-backup + restore route.
    $created = snap_create($pdo);
    gate_verify(snap_name($created) && is_file(snap_path($created)),
        'snapshot can create an archive under a nested catalog lock');
    gate_blocked($peer, 'snapshot releases only its nested lock, not the outer owner');

    snap_restore($pdo, $created);
    gate_blocked($peer, 'restore releases only its nested lock, not the outer owner');
    gate_verify(!$pdo->inTransaction(), 'restore leaves no open database transaction');

    catalog_data_unlock($pdo);
    $ownerLocked = false;
    catalog_data_lock($peer, 0);
    catalog_data_unlock($peer);
    gate_verify(true, 'other PHP/MySQL connections can enter after the owner releases');
} finally {
    if ($secondOwned) catalog_data_unlock($pdo);
    if ($ownerLocked) catalog_data_unlock($pdo);
    if ($created !== null && snap_name($created)) @unlink(snap_path($created));
}

// Route coverage is checked in the source, alongside real authenticated HTTP
// tests in the other CI jobs. These guards must precede all mutations.
foreach ([
    'admin/kavel.php', 'admin/instellingen.php', 'admin/login.php'
] as $page) {
    $source = (string)file_get_contents($root . '/catalogus-public/' . $page);
    gate_verify(str_contains($source, 'catalog_lock_for_request($pdo);'),
        "{$page} acquires mutex for state-changing POST");
}
$backup = (string)file_get_contents($root . '/catalogus-public/admin/backup.php');
gate_verify(str_contains($backup, 'catalog_data_lock($pdo);')
    && str_contains($backup, 'snap_create($pdo);')
    && str_contains($backup, 'snap_restore($pdo,$name);'),
    'backup handler covers safety snapshot and restore with one outer lock');

// Simulate a terminated PHP worker: MySQL must release its named lock when
// the owning connection dies, never leaving future admin work deadlocked.
if (!function_exists('proc_open')) {
    throw new RuntimeException('T-051 requires proc_open in isolated CI.');
}
$script = 'require ' . var_export($root . '/private/bootstrap.php', true) . ';'
    . 'require ' . var_export($root . '/private/catalog_mutex.php', true) . ';'
    . 'catalog_data_lock(db(),0); echo "LOCKED\n"; flush(); usleep(20000000);';
$child = proc_open([PHP_BINARY, '-r', $script],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes, $root);
if (!is_resource($child)) throw new RuntimeException('Cannot start disposable lock owner.');
try {
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 5);
    $handshake = fgets($pipes[1]);
    gate_verify(trim((string)$handshake) === 'LOCKED',
        'a disposable child PHP worker acquired the catalog lock');
    gate_blocked($peer, 'child lock prevents concurrent parent writer');
    proc_terminate($child, 9);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($child);
    $child = null;
    // A dead MySQL session releases named locks automatically.
    $released = false;
    for ($attempt = 0; $attempt < 10; $attempt++) {
        try {
            catalog_data_lock($peer, 0);
            $released = true;
            catalog_data_unlock($peer);
            break;
        } catch (RuntimeException $e) {
            usleep(100000);
        }
    }
    gate_verify($released, 'SIGKILL of the lock owner does not leave a permanent DB mutex');
} finally {
    if (is_resource($child)) proc_terminate($child, 9);
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    if (is_resource($child)) proc_close($child);
}
echo "T-051 MySQL catalog mutation lock: {$passed} isolated checks passed.\n";
