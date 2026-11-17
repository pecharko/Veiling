<?php
declare(strict_types=1);

/**
 * Password-free, read-only connectivity check for the fixed OVH IMAP endpoint.
 * No IMAP LOGIN, credentials, mailbox data or user-controlled host.
 *
 * Returns only 'reachable' or 'unavailable'. Detailed socket and TLS errors
 * must never be displayed, included in session state or written to a log.
 */
function auction_mail_tls_probe(): string {
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
    try {
        $stream = @stream_socket_client(
            'tls://' . $host . ':993', $errno, $errstr,
            4.0, STREAM_CLIENT_CONNECT, $context
        );
        if (!is_resource($stream)) return 'unavailable';
        try {
            @stream_set_timeout($stream, 4);
            $greeting = @fgets($stream, 8192);
            $meta = stream_get_meta_data($stream);
            if (($meta['timed_out'] ?? true) || !is_string($greeting)) return 'unavailable';
            return preg_match('/^\* OK(?:\s|$)/i', $greeting) ? 'reachable' : 'unavailable';
        } finally {
            @fclose($stream);
        }
    } catch (Throwable $ignored) {
        return 'unavailable';
    }
}
