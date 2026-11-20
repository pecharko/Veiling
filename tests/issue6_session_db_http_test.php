<?php
declare(strict_types=1);
/**
 * Issue #6: real PHP HTTP sessions against disposable CI MySQL only.
 * Run after the shared kavel CI localhost:8090 server and fixture are started.
 * Never contact OVH or use production accounts, cookies, or credentials.
 */
require dirname(__DIR__) . '/private/bootstrap.php';
if (getenv('GITHUB_ACTIONS') !== 'true'
    || cfg('setup_key') !== 'ci-test-only-nonproduction'
    || (cfg('database')['host'] ?? '') !== '127.0.0.1'
    || (cfg('database')['name'] ?? '') !== 'auction_test'
    || db()->query('SELECT DATABASE()')->fetchColumn() !== 'auction_test') {
    throw new RuntimeException('REFUSE: this test requires disposable localhost CI MySQL');
}

$issue6Checks = 0;
$issue6Jars = [];
$issue6Ids = [];
function issue6_assert(bool $ok, string $label): void {
    global $issue6Checks;
    if (!$ok) throw new RuntimeException('Issue #6 integration failed: ' . $label);
    $issue6Checks++;
}
function issue6_request(string $jar, string $path, ?array $post = null, ?string $replayCookie = null): array {
    if (!str_starts_with($path, '/admin/')) throw new RuntimeException('Non-admin test path refused');
    $headers = [];
    $curl = curl_init('http://localhost:8090' . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))][] = trim($value);
            }
            return strlen($line);
        },
    ]);
    if ($post !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    if ($replayCookie !== null) curl_setopt($curl, CURLOPT_COOKIE, 'catalog_admin=' . $replayCookie);
    $body = curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($body === false) {
        curl_close($curl);
        throw new RuntimeException('Isolated localhost HTTP service unavailable');
    }
    curl_close($curl);
    return [$code, (string)$body, $headers];
}
function issue6_csrf(string $html): string {
    if (!preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $found)) {
        throw new RuntimeException('No synthetic CSRF field in admin HTML');
    }
    return $found[1];
}
function issue6_cookie_id(array $headers): string {
    foreach (($headers['set-cookie'] ?? []) as $cookie) {
        if (preg_match('/(?:^|\s)catalog_admin=([A-Za-z0-9,-]+)/', $cookie, $found)) {
            return $found[1];
        }
    }
    throw new RuntimeException('No admin session cookie in synthetic HTTP response');
}
function issue6_jar(): string {
    global $issue6Jars;
    $path = tempnam(sys_get_temp_dir(), 'issue6-http-');
    if (!is_string($path)) throw new RuntimeException('Could not create isolated cookie jar');
    $issue6Jars[] = $path;
    return $path;
}
function issue6_login(string $email): array {
    $jar = issue6_jar();
    [$loginCode, $form, $initialHeaders] = issue6_request($jar, '/admin/login.php');
    issue6_assert($loginCode === 200, 'login form for fictitious account');
    $initialId = issue6_cookie_id($initialHeaders);
    [$postCode, , $postHeaders] = issue6_request($jar, '/admin/login.php', [
        'csrf' => issue6_csrf($form),
        'email' => $email,
        'password' => 'ci-issue6-only-not-a-secret',
    ]);
    issue6_assert($postCode === 303, 'fictitious login succeeds');
    $loggedInId = issue6_cookie_id($postHeaders);
    issue6_assert($initialId !== $loggedInId, 'session ID rotated on login');
    [$dashboardStatus, $dashboard] = issue6_request($jar, '/admin/index.php');
    issue6_assert($dashboardStatus === 200, 'authenticated dashboard available');
    return ['jar' => $jar, 'session' => $loggedInId, 'csrf' => issue6_csrf($dashboard)];
}
function issue6_expire_real_php_session(string $id, int $expectedAdminId): void {
    // PHP CLI and localhost:8090 share the CI runner's real PHP session files.
    // This simulates elapsed inactivity without waiting or touching any database.
    if (session_status() !== PHP_SESSION_NONE) throw new RuntimeException('Unexpected test harness PHP session');
    ini_set('session.use_strict_mode', '1');
    session_name('catalog_admin');
    session_id($id);
    if (!session_start()) throw new RuntimeException('Unable to reopen synthetic PHP session');
    if ((int)($_SESSION['admin_id'] ?? 0) !== $expectedAdminId) {
        session_write_close();
        throw new RuntimeException('Synthetic PHP session does not match expected DB principal');
    }
    $_SESSION['admin_last_activity_at'] = time() - 3600;
    session_write_close();
}

$pdo = db();
$accounts = [
    'administrator' => 'ci-issue6-admin@example.invalid',
    'veilingmeester' => 'ci-issue6-auctioneer@example.invalid',
    'assistant' => 'ci-issue6-assistant@example.invalid',
];
try {
    foreach ($accounts as $role => $email) {
        $insert = $pdo->prepare('INSERT INTO admins (name,email,password_hash,role,is_active) VALUES (?,?,?,?,1)');
        $insert->execute(['Issue 6 CI only', $email, password_hash('ci-issue6-only-not-a-secret', PASSWORD_DEFAULT), $role]);
        $issue6Ids[$role] = (int)$pdo->lastInsertId();
    }
    $anonymous = issue6_jar();
    [$anonymousStatus] = issue6_request($anonymous, '/admin/index.php');
    issue6_assert($anonymousStatus === 303, 'anonymous dashboard rejected');
    [$anonymousUsers] = issue6_request($anonymous, '/admin/gebruikers.php');
    issue6_assert($anonymousUsers === 303, 'anonymous user management rejected');

    $assistant = issue6_login($accounts['assistant']);
    [$assistantUsers] = issue6_request($assistant['jar'], '/admin/gebruikers.php');
    issue6_assert($assistantUsers === 403, 'assistant cannot access administrator users route');
    [$assistantSettings] = issue6_request($assistant['jar'], '/admin/instellingen.php');
    issue6_assert($assistantSettings === 403, 'assistant cannot open protected settings');

    $master = issue6_login($accounts['veilingmeester']);
    [$masterUsers] = issue6_request($master['jar'], '/admin/gebruikers.php');
    issue6_assert($masterUsers === 403, 'auctioneer cannot access administrator users route');
    [$masterSettings] = issue6_request($master['jar'], '/admin/instellingen.php');
    issue6_assert($masterSettings === 403, 'auctioneer cannot open protected settings');

    $admin = issue6_login($accounts['administrator']);
    [$adminUsers] = issue6_request($admin['jar'], '/admin/gebruikers.php');
    issue6_assert($adminUsers === 200, 'administrator can open users route');
    [$renewGet] = issue6_request($admin['jar'], '/admin/sessie-verlengen.php');
    issue6_assert($renewGet === 405, 'session renewal GET refused');
    [$renewBad] = issue6_request($admin['jar'], '/admin/sessie-verlengen.php', ['csrf' => 'bad']);
    issue6_assert($renewBad === 403, 'session renewal rejects invalid CSRF');
    [$renewOk, $renewBody, $renewHeaders] = issue6_request($admin['jar'], '/admin/sessie-verlengen.php', ['csrf' => $admin['csrf']]);
    $renewPayload = json_decode($renewBody, true);
    issue6_assert($renewOk === 200 && ($renewPayload['ok'] ?? null) === true
        && abs((int)($renewPayload['expires_at'] ?? 0) - (time() + 3600)) < 30
        && in_array('no-store', $renewHeaders['cache-control'] ?? [], true), 'session renewal uses live DB login, CSRF and 60m expiry');

    // The real PDO-backed request must recognize a role downgrade immediately.
    $pdo->prepare("UPDATE admins SET role='assistant' WHERE id=?")->execute([$issue6Ids['administrator']]);
    [$downgradedUsers] = issue6_request($admin['jar'], '/admin/gebruikers.php');
    issue6_assert($downgradedUsers === 403, 'DB role downgrade immediately denies admin-only page');
    $pdo->prepare("UPDATE admins SET role='administrator' WHERE id=?")->execute([$issue6Ids['administrator']]);

    // Mutate only the disposable PHP session store, then exercise real HTTP.
    issue6_expire_real_php_session($master['session'], $issue6Ids['veilingmeester']);
    [$expiredRenew] = issue6_request($master['jar'], '/admin/sessie-verlengen.php', ['csrf' => $master['csrf']]);
    issue6_assert($expiredRenew === 303, 'expired session cannot be renewed after 60m');
    [$expiredOverview] = issue6_request($master['jar'], '/admin/index.php');
    issue6_assert($expiredOverview === 303, 'expired session cannot access dashboard');

    // Disable a real synthetic account and check its already-active session.
    $pdo->prepare('UPDATE admins SET is_active=0 WHERE id=?')->execute([$issue6Ids['assistant']]);
    [$disabled] = issue6_request($assistant['jar'], '/admin/index.php');
    issue6_assert($disabled === 303, 'disabled account immediately loses dashboard access');

    // Successful POST+CSRF logout must revoke the old session on the server.
    [$logoutGet] = issue6_request($admin['jar'], '/admin/logout.php');
    issue6_assert($logoutGet === 405, 'logout GET refused');
    [$logoutBad] = issue6_request($admin['jar'], '/admin/logout.php', ['csrf' => 'bad']);
    issue6_assert($logoutBad === 403, 'logout invalid CSRF refused');
    [$stillLoggedIn] = issue6_request($admin['jar'], '/admin/index.php');
    issue6_assert($stillLoggedIn === 200, 'invalid logout did not destroy session');
    [$logoutOk] = issue6_request($admin['jar'], '/admin/logout.php', ['csrf' => $admin['csrf']]);
    issue6_assert($logoutOk === 303, 'authorized logout succeeds');
    $replay = issue6_jar();
    [$replayStatus] = issue6_request($replay, '/admin/index.php', null, $admin['session']);
    issue6_assert($replayStatus === 303, 'old session ID cannot be replayed after logout');
} finally {
    // Login analytics may record fictitious admin IDs without an FK. Remove
    // only these synthetic test rows before removing the test accounts.
    foreach ($issue6Ids as $id) {
        $pdo->prepare('DELETE FROM visitor_stats_ip_exclusions WHERE admin_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM admins WHERE id=?')->execute([$id]);
    }
    foreach ($issue6Jars as $jar) {
        if (is_file($jar)) unlink($jar);
    }
}
echo "PASS {$issue6Checks} isolated MySQL-backed admin session and role HTTP checks\n";
