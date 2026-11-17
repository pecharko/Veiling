<?php
declare(strict_types=1);

// Offline fail-closed contract. This test never contacts OVH or uses real credentials.
$root = dirname(__DIR__);
$endpoint = file_get_contents($root . '/catalogus-public/admin/mail-ongelezen.php');
$bootstrap = file_get_contents($root . '/private/bootstrap.php');
$client = file_get_contents($root . '/catalogus-public/admin/mail-unread.js');
$helper = file_get_contents($root . '/private/mail_unread.php');
$css = file_get_contents($root . '/catalogus-public/admin/admin.css');
if (!is_string($endpoint) || !is_string($bootstrap) || !is_string($client)
    || !is_string($helper) || !is_string($css)) {
    throw new RuntimeException('Mail counter source missing');
}
$mustContain = static function (string $source, array $fragments): void {
    foreach ($fragments as $fragment) {
        if (!str_contains($source, $fragment)) {
            throw new RuntimeException('Missing mail counter guard: ' . $fragment);
        }
    }
};
$mustContain($bootstrap, [
    "'rollen.php', 'mail-ongelezen.php', 'logout.php'",
    'data-mail-unread-url=',
    'admin-mail-badge',
    'admin/mail-unread.js',
    "in_array(\$activeRole, ['administrator', 'veilingmeester'], true)",
]);
$mustContain($endpoint, [
    'require_auth();',
    "in_array(effective_admin_role(), ['administrator', 'veilingmeester'], true)",
    'http_response_code(403)',
    'http_response_code(503)',
    "cfg('mail_unread')",
    "['available' => true, 'unread' => \$count]",
    'Cache-Control: no-store',
]);
$mustContain($helper, [
    'tls://imap.mail.ovh.ca:993',
    'verify_peer',
    'verify_peer_name',
    'A002 STATUS INBOX (UNSEEN)',
    'A003 LOGOUT',
    'veiling@41lokeren.be',
    "'enabled'",
    'stream_socket_client',
]);
$mustContain($client, [
    'credentials: \'same-origin\'',
    'cache: \'no-store\'',
    '120000',
    'badge.hidden = true',
    'Number.isSafeInteger(data.unread)',
]);
$mustContain($css, ['.admin-mail-badge[hidden]{display:none}']);
if (str_contains($endpoint, 'imap_open(') || str_contains($client, 'password=')
    || str_contains($client, 'veiling@41lokeren.be')) {
    throw new RuntimeException('Mail client must not use mailbox credentials');
}
require_once $root . '/private/mail_unread.php';
if (auction_mail_unread_count(null) !== null
    || auction_mail_unread_count(['enabled' => false]) !== null
    || auction_mail_unread_count(['enabled' => true, 'user' => 'invalid', 'password' => 'dummy']) !== null) {
    throw new RuntimeException('Unconfigured mailbox must fail closed');
}
echo "PASS read-only role-gated mail counter, no credentials in browser and fail closed\n";
