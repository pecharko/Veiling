<?php
/** Private, explicitly installed CLI bootstrap. No HTTP, env activation or DDL. */
declare(strict_types=1);
require_once __DIR__ . '/ovh_controller_claim_executor.php';

function ovh_runtime_config(string $pin): array {
    if (!ovh_claim_hash($pin) || !preg_match('/^[0-9a-f]{40}$/D', basename(__DIR__))) {
        throw new RuntimeException('Unreviewed runtime baseline');
    }
    // Fixed sibling location: <private_root>/config/<immutable_revision>.json.
    $path = dirname(__DIR__) . '/config/' . basename(__DIR__) . '.json';
    if (realpath($path) !== $path) throw new RuntimeException('Unsafe private config path');
    $before = lstat($path);
    if (!$before || !function_exists('posix_geteuid') || $before['uid'] !== posix_geteuid()
        || ($before['mode'] & 0170000) !== 0100000 || ($before['mode'] & 0777) !== 0600
        || $before['nlink'] !== 1 || $before['size'] < 1 || $before['size'] > 16384) {
        throw new RuntimeException('Private config isolation missing');
    }
    $stream = fopen($path, 'rb');
    if ($stream === false) throw new RuntimeException('Missing private config');
    try {
        $opened = fstat($stream);
        if (!$opened || $opened['ino'] !== $before['ino'] || $opened['dev'] !== $before['dev']) {
            throw new RuntimeException('Private config changed');
        }
        $raw = stream_get_contents($stream, 16385);
    } finally { fclose($stream); }
    if (!is_string($raw) || strlen($raw) > 16384 || !hash_equals($pin, hash('sha256', $raw))) {
        throw new RuntimeException('Private config digest drift');
    }
    $cfg = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($cfg) || ovh_claim_canonical($cfg) . "\n" !== $raw) {
        throw new RuntimeException('Noncanonical private config');
    }
    ovh_claim_keys($cfg, ['schema','dsn','user','password','hmac_base64']);
    if ($cfg['schema'] !== 1 || !is_string($cfg['dsn'])
        || !preg_match('/^mysql:host=[A-Za-z0-9._-]+;port=([0-9]{1,5});dbname=[A-Za-z0-9_-]+;charset=utf8mb4$/D', $cfg['dsn'], $m)
        || (int)$m[1] < 1 || (int)$m[1] > 65535
        || !is_string($cfg['user']) || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $cfg['user'])
        || !is_string($cfg['password']) || strlen($cfg['password']) < 1 || strlen($cfg['password']) > 1024
        || !is_string($cfg['hmac_base64'])) throw new RuntimeException('Invalid private datasource');
    $key = base64_decode($cfg['hmac_base64'], true);
    if (!is_string($key) || strlen($key) < 32 || strlen($key) > 1024
        || base64_encode($key) !== $cfg['hmac_base64']) throw new RuntimeException('Invalid private MAC key');
    return [$cfg, $key];
}

function ovh_runtime_main(array $args): int {
    if (PHP_SAPI !== 'cli' || count($args) !== 2 || !ovh_claim_hash($args[1])) {
        echo json_encode(ovh_claim_execute(), JSON_THROW_ON_ERROR), "\n";
        return 78;
    }
    try {
        [$cfg, $key] = ovh_runtime_config($args[1]);
        // The existing HMAC/schema/expiry validation runs before this factory.
        $factory = fn()=>new OvhMysqlClaimStore(new PDO($cfg['dsn'], $cfg['user'], $cfg['password'], [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_LOCAL_INFILE=>false,
        ]));
        return ovh_claim_stdio(STDIN, STDOUT, $key, time(), $factory);
    } catch (Throwable $e) {
        echo "{\"dispatch_started\":false,\"retry_allowed\":false,\"status\":\"REJECTED\"}\n";
        return 65;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    // Suppress PHP warnings: no config path, PDO error or payload in output.
    set_error_handler(static function (): never { throw new RuntimeException('Private runtime failure'); });
    exit(ovh_runtime_main($argv));
}
