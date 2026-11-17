<?php
declare(strict_types=1);

/**
 * Optional CLI-only, unauthenticated OVH IMAP reachability probe.
 *
 * Run ONLY on an explicitly authorized server shell. No mailbox credentials,
 * no session, no IMAP LOGIN, no message access, no secret/config loading.
 * Fixed host, pinned hostname verification, timeout. Never deploy to webroot.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$host = 'imap.mail.ovh.ca';
$context = stream_context_create(['ssl' => [
    'verify_peer' => true,
    'verify_peer_name' => true,
    'peer_name' => $host,
    'SNI_enabled' => true,
    'allow_self_signed' => false,
]]);
$errno = 0;
$errstr = '';
$stream = @stream_socket_client('tls://' . $host . ':993', $errno, $errstr,
    5.0, STREAM_CLIENT_CONNECT, $context);
if (!is_resource($stream)) {
    fwrite(STDERR, "IMAP TLS reachability: FAIL (no credentials used)\n");
    exit(1);
}
@stream_set_timeout($stream, 5);
$greeting = @fgets($stream, 8192);
@fclose($stream);
if (!is_string($greeting) || !preg_match('/^\* OK(?:\s|$)/i', $greeting)) {
    fwrite(STDERR, "IMAP TLS reachability: FAIL (no valid greeting)\n");
    exit(1);
}
fwrite(STDOUT, "IMAP TLS reachability: PASS (verified TLS + greeting, no credentials used)\n");
