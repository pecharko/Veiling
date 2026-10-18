<?php
declare(strict_types=1);

/** Authenticated HTTP test; localhost + disposable CI schema ONLY. */
if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('CI_SNAPSHOT_TEST') !== 'yes'
    || getenv('CI_DB_HOST') !== '127.0.0.1'
    || getenv('CI_DB_NAME') !== 'auction_test') {
    throw new RuntimeException('Snapshot HTTP tests require the isolated CI environment.');
}
require dirname(__DIR__) . '/private/bootstrap.php';
require dirname(__DIR__) . '/private/snapshots.php';
if (db()->query('SELECT DATABASE()')->fetchColumn() !== 'auction_test') {
    throw new RuntimeException('Unexpected test database.');
}

$base = 'http://localhost:8090';
$jar = tempnam(sys_get_temp_dir(), 'snap-test-cookie');
$count = 0;
function ensure(bool $ok, string $message): void {
    global $count;
    ++$count;
    if (!$ok) throw new RuntimeException("FAILED: {$message}");
    echo "PASS: {$message}\n";
}
function send(string $route, ?array $post = null): array {
    global $base, $jar;
    $ch = curl_init($base . $route);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HEADER => true,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $payload = curl_exec($ch);
    if ($payload === false) throw new RuntimeException(curl_error($ch));
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerLen = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, substr($payload, 0, $headerLen), substr($payload, $headerLen)];
}
function csrf_from(string $html): string {
    if (!preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $matches)) {
        throw new RuntimeException('CSRF token missing.');
    }
    return $matches[1];
}

try {
    [$status] = send('/admin/backup.php');
    ensure($status === 303, 'Anonymous backup page redirects to login');

    [$status, , $login] = send('/admin/login.php');
    ensure($status === 200, 'Login page available');
    [$status] = send('/admin/login.php', [
        'csrf'=>csrf_from($login),
        'email'=>'ci-admin@example.invalid',
        'password'=>'ci-password-not-a-real-secret',
    ]);
    ensure($status === 303, 'Administrator can log in to isolated test app');

    [$status, , $page] = send('/admin/backup.php');
    ensure($status === 200 && str_contains($page, 'Back-up &amp; herstel'), 'Authenticated backup UI renders');
    ensure(str_contains($page, 'Hostinggebruik (geschat)')
       && str_contains($page, 'Geschatte resterende ruimte')
       && str_contains($page, 'Snapshots ('), 'Storage overview fields render');
    ensure(str_contains($page, 'Snapshot maken')
       && str_contains($page, 'Geselecteerde snapshot herstellen')
       && str_contains($page, 'VERWIJDER'), 'Create/restore/delete actions are present');
    $csrf = csrf_from($page);
    $existing = snap_listing();
    ensure(count($existing) === 1, 'Prior CLI snapshot available in admin listing');
    $originalName = $existing[0]['name'];
    ensure(str_contains($page, $originalName), 'Saved snapshot appears in admin list');

    [$status, $headers, $body] = send('/admin/backup.php?download=' . rawurlencode($originalName));
    ensure($status === 200
        && str_contains(strtolower($headers), 'application/zip')
        && str_starts_with($body, "PK")
        && strlen($body) === filesize(snap_path($originalName)), 'Authenticated ZIP download contains exact snapshot bytes');
    [$status] = send('/admin/backup.php?download=..%2Fconfig.php');
    ensure($status === 404, 'Invalid snapshot download path is rejected');

    [$status] = send('/admin/backup.php', ['action'=>'create']);
    ensure($status === 403 && count(snap_listing()) === 1,
           'Invalid CSRF cannot create a snapshot');
    [$status] = send('/admin/backup.php', ['csrf'=>$csrf, 'action'=>'create']);
    ensure($status === 303 && count(snap_listing()) === 2,
           'Authenticated create action produces new snapshot and redirects');
    $newName = array_values(array_filter(
        array_column(snap_listing(), 'name'), fn($item) => $item !== $originalName
    ))[0];
    ensure(is_file(snap_path($newName)), 'New snapshot exists on isolated filesystem');

    [$status, , $page] = send('/admin/backup.php');
    $csrf = csrf_from($page);
    [$status, , $html] = send('/admin/backup.php', [
        'csrf'=>$csrf,'action'=>'delete','snapshot'=>$newName,'confirm'=>'NO',
    ]);
    ensure($status === 200 && is_file(snap_path($newName))
        && str_contains($html,'VERWIJDER'), 'Delete rejects incorrect confirmation');
    [$status, , $html] = send('/admin/backup.php', [
        'csrf'=>$csrf,'action'=>'restore','snapshot'=>$originalName,'confirm'=>'NO',
    ]);
    ensure($status === 200 && count(snap_listing()) === 2
        && str_contains($html, 'Typ HERSTEL'), 'Restore rejects incorrect confirmation without safety snapshot');

    [$status] = send('/admin/backup.php', [
        'csrf'=>$csrf,'action'=>'delete','snapshot'=>$newName,'confirm'=>'VERWIJDER',
    ]);
    clearstatcache(true, snap_path($newName));
    ensure($status === 303 && !is_file(snap_path($newName))
        && count(snap_listing()) === 1, 'Confirmed delete removes only selected snapshot');

    db()->exec("UPDATE lots SET title='WEB changed data' WHERE lot_number=101");
    [$status, $headers] = send('/admin/backup.php', [
        'csrf'=>$csrf,'action'=>'restore','snapshot'=>$originalName,'confirm'=>'HERSTEL',
    ]);
    ensure($status === 303 && str_contains($headers, '/admin/login.php'),
           'Confirmed restore redirects back to login');
    ensure(db()->query('SELECT title FROM lots WHERE lot_number=101')->fetchColumn()
        === 'Kunstwerk voor snapshot', 'Confirmed HTTP restore recovers the original database');
    ensure(count(snap_listing()) === 2, 'Restore creates safety snapshot before applying archive');
    [$status] = send('/admin/backup.php');
    ensure($status === 303, 'Restore invalidates existing admin session');
    echo "Snapshot HTTP administration: {$count} checks passed on isolated test app.\n";
} finally {
    @unlink($jar);
}
