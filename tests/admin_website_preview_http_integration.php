<?php
declare(strict_types=1);
/** Real HTTP/session/CSRF + disposable MySQL; refuses all production targets. */
require dirname(__DIR__) . '/private/bootstrap.php';
require_once dirname(__DIR__) . '/private/analytics_control.php';
if (getenv('GITHUB_ACTIONS') !== 'true' || cfg('setup_key') !== 'ci-test-only-nonproduction'
    || (cfg('database')['host'] ?? '') !== '127.0.0.1'
    || db()->query('SELECT DATABASE()')->fetchColumn() !== 'auction_test') {
    throw new RuntimeException('Only isolated localhost CI fixture permitted');
}
$pdo = db();
$jar = tempnam(sys_get_temp_dir(), 'preview-admin-');
$guestJar = tempnam(sys_get_temp_dir(), 'preview-guest-');
$checks = 0;
function preview_check(bool $ok, string $message): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException($message);
    echo "PASS $message\n";
}
function preview_request(string $path, ?array $data = null, bool $guest = false): array {
    global $jar, $guestJar;
    $cookie = $guest ? $guestJar : $jar;
    $headers = [];
    $ch = curl_init('http://localhost:8090' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_COOKIEFILE=>$cookie, CURLOPT_COOKIEJAR=>$cookie, CURLOPT_TIMEOUT=>15,
        CURLOPT_USERAGENT=>'CI genuine browser',
        CURLOPT_HEADERFUNCTION=>static function ($ch, string $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$key,$value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))][] = trim($value);
            }
            return strlen($line);
        }]);
    if ($data !== null) curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query($data)]);
    $body = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($body === false) throw new RuntimeException('Local HTTP fixture unavailable');
    return [$status,(string)$body,$headers];
}
function preview_csrf(string $body): string {
    if (!preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $m)) throw new RuntimeException('Missing CSRF');
    return $m[1];
}
function preview_login(): string {
    [$status,$body] = preview_request('/admin/login.php');
    preview_check($status === 200, 'Local admin login form');
    [$status] = preview_request('/admin/login.php', ['csrf'=>preview_csrf($body),
        'email'=>'ci-admin@example.invalid','password'=>'ci-password-not-a-real-secret']);
    preview_check($status === 303, 'Authenticated preview principal');
    [$status,$body] = preview_request('/admin/index.php');
    preview_check($status === 200 && str_contains($body, 'name="admin_preview_action" value="start"')
        && str_contains($body, 'target="_blank"'), 'Menu provides POST preview in new tab');
    return preview_csrf($body);
}
function preview_views(): int {
    return (int)db()->query('SELECT COALESCE(SUM(views),0) FROM visitor_stats_daily')->fetchColumn();
}
$settingsBefore = settings();
$accountBefore = $pdo->query("SELECT id,password_hash,is_active,role FROM admins WHERE email='ci-admin@example.invalid'")->fetch();
$wasEnabled = analytics_collection_enabled();
$geoWasDisabled = is_file(visitor_geo_disabled_marker());
try {
    set_analytics_collection_enabled(true);
    foreach (['database/visitor_stats.sql','database/visitor_stats_aggregates.sql',
              'database/migrations/20261029_visitor_geo_analytics.sql'] as $fixtureSql) {
        $sql = file_get_contents(dirname(__DIR__).'/'.$fixtureSql);
        $sql = preg_replace('/^\s*--[^\n]*$/m', '', (string)$sql);
        foreach (explode(';', (string)$sql) as $statement) if (trim($statement) !== '') $pdo->exec($statement);
    }
    $pdo->exec("ALTER TABLE admins MODIFY COLUMN role ENUM('administrator','assistant','veilingmeester') NOT NULL DEFAULT 'administrator'");
    visitor_geo_set_enabled($pdo);
    $pdo->exec('DELETE FROM visitor_geo_daily');
    $pdo->exec('DELETE FROM visitor_stats_daily');
    $pdo->exec('UPDATE settings SET is_published=0,is_site_online=1 WHERE id=1');
    $insert = $pdo->prepare('INSERT INTO lots (lot_number,title,category,description,details,status) VALUES (?,?,?,?,?,?)');
    foreach ([990101=>'published',990102=>'sold',990103=>'draft'] as $number=>$state) {
        $insert->execute([$number,'Preview fixture '.$state,'Preview CI','','',$state]);
    }

    [$status,$body,$headers] = preview_request('/index.php?admin_preview=1', null, true);
    preview_check($status === 200 && !str_contains($body,'Preview fixture') && !str_contains($body,'ADMINVOORBEELD'), 'Anonymous query cannot reveal hidden lots');
    preview_check(!str_contains(implode(' ', $headers['set-cookie'] ?? []), 'catalog_admin='), 'Ordinary visitor receives no admin session cookie');
    [$status] = preview_request('/kavel.php?nr=990101', null, true);
    preview_check($status === 404, 'Anonymous direct lot remains hidden');
    [$status] = preview_request('/index.php', ['admin_preview_action'=>'start','csrf'=>'invalid'], true);
    preview_check($status === 303, 'Anonymous preview POST requires login');

    $csrf = preview_login();
    [$status,$body] = preview_request('/index.php?admin_preview_action=start');
    preview_check($status === 200 && !str_contains($body,'ADMINVOORBEELD') && !str_contains($body,'Preview fixture'), 'Login alone or GET flag cannot enter preview');
    $before = preview_views();
    $geoBefore = (int)$pdo->query('SELECT COALESCE(SUM(views),0) FROM visitor_geo_daily')->fetchColumn();
    preview_check($geoBefore >= 2, 'Ordinary geo views calibrated before preview');
    preview_check($before >= 2, 'Real ordinary browser views are counted before preview');
    [$status] = preview_request('/index.php', ['admin_preview_action'=>'start','csrf'=>'invalid']);
    preview_check($status === 403, 'Missing valid CSRF blocks preview activation');
    [$status] = preview_request('/index.php', ['admin_preview_action'=>'start','csrf'=>$csrf]);
    preview_check($status === 303, 'Valid authenticated CSRF POST starts preview');
    [$status,$body,$headers] = preview_request('/index.php');
    preview_check($status === 200 && str_contains($body,'ADMINVOORBEELD')
        && str_contains($body,'Preview fixture published') && str_contains($body,'Preview fixture sold')
        && !str_contains($body,'Preview fixture draft'), 'Preview overrides global toggle but never exposes drafts');
    preview_check(str_contains(implode(' ', $headers['cache-control'] ?? []),'no-store')
        && str_contains(implode(' ', $headers['cache-control'] ?? []),'private')
        && str_contains(implode(' ', $headers['vary'] ?? []),'Cookie')
        && str_contains(implode(' ', $headers['x-robots-tag'] ?? []),'noindex'), 'Preview is private, non-cacheable and non-indexable');
    preview_check(strpos($body,'ADMINVOORBEELD') < strpos($body,'class="site-header'), 'Banner precedes public header');
    $closeCsrf = preview_csrf($body);
    foreach (['/kavel.php?nr=990101','/kavel.php?nr=990102','/privacy.php',
              '/index.php?q=Preview&categorie=Preview%20CI','/index.php?favorieten=1&nummers=990101'] as $path) {
        [$status,$body] = preview_request($path);
        preview_check($status === 200 && str_contains($body,'ADMINVOORBEELD'), 'Preview survives detail/filter/favorites/privacy navigation');
    }
    [$status] = preview_request('/kavel.php?nr=990103');
    preview_check($status === 404, 'Preview direct draft URL stays forbidden');
    preview_check(preview_views() === $before, 'Preview home/detail/navigation produces no statistics writes');
    preview_check((int)$pdo->query('SELECT COALESCE(SUM(views),0) FROM visitor_geo_daily')->fetchColumn() === $geoBefore, 'Preview also produces no geographic statistics writes');
    preview_check((int)settings()['is_published'] === 0, 'Preview does not publish global settings');
    [$status,$body] = preview_request('/index.php', null, true);
    preview_check($status === 200 && !str_contains($body,'ADMINVOORBEELD') && !str_contains($body,'Preview fixture'), 'Concurrent public session remains unaffected');
    preview_check(preview_views() === $before+1, 'Concurrent ordinary visitor is still counted');
    $before = preview_views();
    [$status] = preview_request('/index.php', ['admin_preview_action'=>'stop','csrf'=>'invalid']);
    preview_check($status === 403, 'CSRF also protects closing preview');
    $pdo->exec('UPDATE settings SET is_site_online=0 WHERE id=1');
    [$status,$body] = preview_request('/index.php');
    preview_check($status === 503 && str_contains($body,'Binnenkort online'), 'Preview never bypasses website-offline gate');
    $pdo->exec('UPDATE settings SET is_site_online=1 WHERE id=1');
    [$status] = preview_request('/index.php', ['admin_preview_action'=>'stop','csrf'=>$closeCsrf]);
    preview_check($status === 303, 'Preview can be closed explicitly');
    [$status,$body] = preview_request('/index.php');
    preview_check($status === 200 && !str_contains($body,'ADMINVOORBEELD') && !str_contains($body,'Preview fixture'), 'Closing restores normal public visibility');
    preview_check(preview_views() === $before, 'Closed preview session stays excluded from statistics');

    foreach (['administrator','assistant','veilingmeester'] as $role) {
        $pdo->prepare('UPDATE admins SET role=? WHERE id=?')->execute([$role,$accountBefore['id']]);
        [, $admin] = preview_request('/admin/index.php');
        [$status] = preview_request('/index.php', ['admin_preview_action'=>'start','csrf'=>preview_csrf($admin)]);
        [$viewStatus,$body] = preview_request('/index.php');
        preview_check($status === 303 && $viewStatus === 200 && str_contains($body,'ADMINVOORBEELD'), 'Existing allowed admin role can preview');
    }
    $pdo->prepare("UPDATE admins SET role='administrator',is_active=0 WHERE id=?")->execute([$accountBefore['id']]);
    [$status,$body] = preview_request('/index.php');
    preview_check($status === 303 && !str_contains($body,'Preview fixture'), 'Disabled account immediately loses preview access');
    $pdo->prepare('UPDATE admins SET is_active=1 WHERE id=?')->execute([$accountBefore['id']]);
    $csrf = preview_login();
    preview_request('/index.php', ['admin_preview_action'=>'start','csrf'=>$csrf]);
    $pdo->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash('another-ci-only-password',PASSWORD_DEFAULT),$accountBefore['id']]);
    [$status,$body] = preview_request('/index.php');
    preview_check($status === 303 && !str_contains($body,'Preview fixture'), 'Password revocation invalidates preview credential proof');
    $pdo->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([$accountBefore['password_hash'],$accountBefore['id']]);
    $csrf = preview_login();
    preview_request('/index.php', ['admin_preview_action'=>'start','csrf'=>$csrf]);
    [$status] = preview_request('/admin/logout.php', ['csrf'=>$csrf]);
    preview_check($status === 303, 'Logout destroys the preview session');
    $before = preview_views();
    [$status,$body] = preview_request('/index.php');
    preview_check($status === 200 && !str_contains($body,'ADMINVOORBEELD') && preview_views() === $before+1, 'After logout normal visitor collection resumes');
} finally {
    $pdo->prepare('UPDATE settings SET is_published=?,is_site_online=? WHERE id=1')->execute([$settingsBefore['is_published'],$settingsBefore['is_site_online'] ?? 1]);
    $pdo->prepare('UPDATE admins SET password_hash=?,is_active=?,role=? WHERE id=?')->execute([$accountBefore['password_hash'],$accountBefore['is_active'],$accountBefore['role'],$accountBefore['id']]);
    $pdo->exec('DELETE FROM lots WHERE lot_number IN (990101,990102,990103)');
    if ($geoWasDisabled) visitor_geo_set_disabled();
    set_analytics_collection_enabled($wasEnabled);
    foreach ([$jar,$guestJar] as $file) if (is_string($file) && is_file($file)) unlink($file);
}
echo "PASS $checks isolated admin preview HTTP/session/analytics checks\n";
