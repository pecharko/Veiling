<?php
declare(strict_types=1);
/** Public impression page honours site availability and lot visibility separately. */
require dirname(__DIR__) . '/private/bootstrap.php';
if (getenv('GITHUB_ACTIONS') !== 'true' || cfg('setup_key') !== 'ci-test-only-nonproduction'
    || (cfg('database')['host'] ?? '') !== '127.0.0.1'
    || db()->query('SELECT DATABASE()')->fetchColumn() !== 'auction_test') {
    throw new RuntimeException('Only isolated localhost CI fixture permitted');
}
function impression_request(string $path): array {
    $ch = curl_init('http://localhost:8090' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('Local fixture unavailable');
    return [$status, $body];
}
function impression_check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS $label\n";
}
$before = db()->query('SELECT is_site_online,is_published FROM settings WHERE id=1')->fetch();
try {
    foreach ([0,1] as $published) {
        db()->exec('UPDATE settings SET is_site_online=1,is_published=' . $published . ' WHERE id=1');
        [$status,$home] = impression_request('/index.php');
        impression_check($status === 200 && str_contains($home,'Bekijk de vorige editie')
            && str_contains($home,'vorige-editie.php'), 'Homepage links to impression with published=' . $published);
        [$status,$body] = impression_request('/vorige-editie.php');
        impression_check($status === 200 && substr_count($body,'class="impression-photo-image"') === 9\n            && !str_contains($body,'data-impression-photo=')\n            && !str_contains($body,'<figcaption')
            && !str_contains($body,'ADMINVOORBEELD'), 'Nine public photos independent of lot publication');
    }
    db()->exec('UPDATE settings SET is_site_online=0 WHERE id=1');
    [$status,$body] = impression_request('/vorige-editie.php');
    impression_check($status === 503 && !str_contains($body,'data-impression-photo='), 'Offline guard also protects impression page');
} finally {
    db()->prepare('UPDATE settings SET is_site_online=?,is_published=? WHERE id=1')->execute([
        $before['is_site_online'], $before['is_published']]);
}
