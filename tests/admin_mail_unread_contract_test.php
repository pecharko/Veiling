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
    "'tls://' . \$host . ':993'",
    "'imap.mail.ovh.ca', 'imap.mail.ovh.net', 'ssl0.ovh.net'",
    "'peer_name' => \$host",
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
    || auction_mail_unread_count(['enabled' => true, 'user' => 'invalid', 'password' => 'dummy']) !== null
    || auction_mail_unread_count(['enabled' => true, 'user' => 'veiling@41lokeren.be', 'host' => 'example.org', 'password' => 'dummy']) !== null) {
    throw new RuntimeException('Unconfigured mailbox must fail closed');
}
// Purely offline IMAP protocol parsing, with fictional responses only.
$stream = fopen('php://temp', 'w+');
if ($stream === false) throw new RuntimeException('Cannot create offline IMAP stream');
fwrite($stream, "* STATUS INBOX (MESSAGES 17 UNSEEN 3 UIDNEXT 91)\r\nA002 OK done\r\n");
rewind($stream);
$reply = auction_mail_imap_reply($stream, 'A002');
fclose($stream);
if ($reply === null || $reply[0] !== true
    || !preg_match('/\bUNSEEN\s+3\b/', implode('', $reply[1]))) {
    throw new RuntimeException('IMAP status parser must receive tagged success and unseen count');
}
$stream = fopen('php://temp', 'w+');
fwrite($stream, "A001 NO rejected\r\n");
rewind($stream);
$denied = auction_mail_imap_reply($stream, 'A001');
fclose($stream);
if ($denied === null || $denied[0] !== false) {
    throw new RuntimeException('IMAP authentication rejection must fail closed');
}
echo "PASS read-only role-gated mail counter, no credentials in browser and fail closed\n";
