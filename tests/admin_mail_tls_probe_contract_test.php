<?php
declare(strict_types=1);

// Offline static contract only. Never perform live network traffic or use credentials.
$root = dirname(__DIR__);
$page = file_get_contents($root . '/catalogus-public/admin/mail-tls-check.php');
$helper = file_get_contents($root . '/private/mail_tls_probe.php');
if (!is_string($page) || !is_string($helper)) throw new RuntimeException('TLS probe code missing');
foreach ([
    'require_administrator();', 'require_no_index();', 'check_csrf();',
    '$_SESSION[\'mail_tls_probe_after\']', "'POST'",
    "auction_mail_tls_probe()", "'recent'", "'reachable'", "'unavailable'",
] as $part) {
    if (!str_contains($page, $part)) throw new RuntimeException('Missing admin/CSRF/rate guard: ' . $part);
}
foreach ([
    "function auction_mail_tls_probe(): string",
    "'imap.mail.ovh.ca'", "':993'", "'verify_peer' => true",
    "'verify_peer_name' => true", "'allow_self_signed' => false",
    'stream_socket_client', 'stream_set_timeout',
    "return 'unavailable'", "? 'reachable' : 'unavailable'",
] as $part) {
    if (!str_contains($helper, $part)) throw new RuntimeException('Missing TLS probe guard: ' . $part);
}
foreach ([$page, $helper] as $src) {
    foreach (['imap_open(', 'A001 LOGIN', 'password', '$_GET[', "cfg('mail_unread')", 'file_put_contents('] as $forbidden) {
        if (str_contains($src, $forbidden)) throw new RuntimeException('Unexpected credentials or dynamic input in TLS probe: ' . $forbidden);
    }
}
echo "PASS isolated administrator-only, CSRF-protected fixed-host IMAP TLS probe contract\n";
