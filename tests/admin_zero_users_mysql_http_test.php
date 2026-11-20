<?php
declare(strict_types=1);
/**
 * Issue #4: real localhost HTTP against the already-disposable CI MySQL.
 * Run ONLY immediately after kavel_integration_setup.php seeded one fake admin.
 * Never run against OVH, a local production clone, or non-CI credentials.
 */
require dirname(__DIR__) . '/private/bootstrap.php';

$config = cfg('database');
if (getenv('GITHUB_ACTIONS') !== 'true'
    || cfg('setup_key') !== 'ci-test-only-nonproduction'
    || ($config['host'] ?? '') !== '127.0.0.1'
    || ($config['name'] ?? '') !== 'auction_test'
    || db()->query('SELECT DATABASE()')->fetchColumn() !== 'auction_test') {
    throw new RuntimeException('REFUSE: requires disposable GitHub CI localhost MySQL');
}
$pdo = db();
$rows = $pdo->query('SELECT * FROM admins')->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) !== 1 || ($rows[0]['email'] ?? '') !== 'ci-admin@example.invalid'
    || (int)($rows[0]['is_active'] ?? 0) !== 1) {
    throw new RuntimeException('REFUSE: unexpected synthetic administrator fixture');
}
$original = $rows[0];
$startAttempts = (int)$pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();
if ($startAttempts !== 0) {
    throw new RuntimeException('REFUSE: expected pristine disposable login attempts');
}
$jar = tempnam(sys_get_temp_dir(), 'issue4-ci-cookie-');
if (!is_string($jar)) throw new RuntimeException('Unable to create CI cookie jar');
$deleted = false;
$checks = 0;
function issue4_check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('Issue #4 CI HTTP failure: ' . $label);
    $checks++;
}
function issue4_request(string $jar, ?array $post = null): array {
    $headers = [];
    $ch = curl_init('http://localhost:8092/admin/login.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))][] = trim($value);
            }
            return strlen($line);
        },
    ]);
    if ($post !== null) {
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    }
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('Isolated HTTP server unavailable');
    return [$status, (string)$body, $headers];
}
try {
    // Only a single fake admin in freshly-created auction_test can be removed.
    // Restore EXACT original row in finally; no external credentials or users.
    $delete = $pdo->prepare('DELETE FROM admins WHERE id=?');
    $delete->execute([(int)$original['id']]);
    issue4_check($delete->rowCount() === 1, 'synthetic admin temporarily absent');
    $deleted = true;
    issue4_check((int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() === 0,
        'real MySQL has zero administrators');

    [$getStatus, $getBody, $getHeaders] = issue4_request($jar);
    issue4_check($getStatus === 503, 'GET login refuses zero-admin MySQL');
    issue4_check(str_contains(strtolower(implode(' ', $getHeaders['cache-control'] ?? [])), 'no-store'),
        'zero-admin response is never cached');
    issue4_check(str_contains($getBody, 'Beheer tijdelijk niet beschikbaar')
        && !str_contains($getBody, 'setup.php')
        && !str_contains(strtolower($getBody), 'password'), 'generic error without public setup or login form');
    [$postStatus] = issue4_request($jar, ['email' => 'ci-admin@example.invalid', 'password' => 'wrong']);
    issue4_check($postStatus === 503, 'POST login refuses zero-admin MySQL before credential processing');
    issue4_check((int)$pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn() === $startAttempts,
        'zero-admin POST does not create login attempts');
} finally {
    if ($deleted) {
        $fields = array_keys($original);
        $columns = implode(',', array_map(static fn(string $f): string => chr(96) . $f . chr(96), $fields));
        $insert = $pdo->prepare('INSERT INTO admins (' . $columns . ') VALUES ('
            . implode(',', array_fill(0, count($fields), '?')) . ')');
        $insert->execute(array_values($original));
    }
}
try {
    $restored = $pdo->query('SELECT * FROM admins')->fetchAll(PDO::FETCH_ASSOC);
    issue4_check(count($restored) === 1 && $restored[0] == $original,
        'original synthetic administrator restored unchanged');
    [$getStatus, $form] = issue4_request($jar);
    issue4_check($getStatus === 200 && str_contains($form, 'name="email"')
        && str_contains($form, 'name="password"'), 'restored fake admin displays normal login');
    issue4_check((bool)preg_match('/name="csrf" value="([a-f0-9]+)"/', $form, $csrf),
        'normal login contains a CSRF token');
    [$loginStatus] = issue4_request($jar, [
        'csrf' => $csrf[1],
        'email' => 'ci-admin@example.invalid',
        'password' => 'ci-password-not-a-real-secret',
    ]);
    issue4_check($loginStatus === 303, 'restored fake administrator logs in normally');
} finally {
    if (is_file($jar)) unlink($jar);
}
echo "PASS {$checks} genuine disposable MySQL/HTTP zero-admin and restored-login checks\n";
