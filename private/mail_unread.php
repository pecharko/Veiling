<?php
declare(strict_types=1);

/**
 * The OVH MX Plan unread counter is opt-in and read-only.
 *
 * Requires private (never committed) config:
 * 'mail_unread' => ['enabled' => true, 'user' => 'veiling@41lokeren.be',
 *                   'host' => '<OVH regional IMAP server>',
 *                   'password' => '<mailbox secret>']
 *
 * Uses only LOGIN, STATUS INBOX (UNSEEN) and LOGOUT over verified TLS.
 * No message is opened, downloaded, marked read or displayed.
 */
function auction_mail_imap_reply($socket, string $tag): ?array {
  $lines = [];
  $bytes = 0;
  for ($i = 0; $i < 100; $i++) {
    $line = @fgets($socket, 8192);
    if (!is_string($line) || $line === '') return null;
    $bytes += strlen($line);
    if ($bytes > 65536) return null;
    $lines[] = $line;
    if (preg_match('/^' . preg_quote($tag, '/') . ' (OK|NO|BAD)\b/i', $line, $m)) {
      return [strtoupper($m[1]) === 'OK', $lines];
    }
  }
  return null;
}
function auction_mail_imap_quote(string $value): string {
  return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}
function auction_mail_unread_configured(mixed $config): bool {
  if (!is_array($config) || ($config['enabled'] ?? false) !== true) return false;
  if (($config['user'] ?? null) !== 'veiling@41lokeren.be') return false;
  $host = $config['host'] ?? null;
  if (!is_string($host) || !in_array($host, [
      'imap.mail.ovh.ca', 'imap.mail.ovh.net', 'ssl0.ovh.net',
    ], true)) return false;
  $password = $config['password'] ?? null;
  return is_string($password) && $password !== '' && strlen($password) <= 1024
    && strpbrk($password, "\r\n\0") === false;
}
function auction_mail_unread_count(mixed $config): ?int {
  if (!auction_mail_unread_configured($config)) return null;
  $host = $config['host'];
  $password = $config['password'];

  $ssl = stream_context_create(['ssl' => [
    'verify_peer' => true,
    'verify_peer_name' => true,
    'peer_name' => $host,
    'SNI_enabled' => true,
    'allow_self_signed' => false,
  ]]);
  $socket = @stream_socket_client('tls://' . $host . ':993', $errorNumber, $errorText,
    5.0, STREAM_CLIENT_CONNECT, $ssl);
  if (!is_resource($socket)) return null;
  @stream_set_timeout($socket, 5);
  try {
    $greeting = @fgets($socket, 8192);
    if (!is_string($greeting) || !str_starts_with($greeting, '* OK')) return null;

    $command = 'A001 LOGIN ' . auction_mail_imap_quote('veiling@41lokeren.be')
      . ' ' . auction_mail_imap_quote($password) . "\r\n";
    if (@fwrite($socket, $command) !== strlen($command)) return null;
    $login = auction_mail_imap_reply($socket, 'A001');
    if ($login === null || !$login[0]) return null;

    $command = "A002 STATUS INBOX (UNSEEN)\r\n";
    if (@fwrite($socket, $command) !== strlen($command)) return null;
    $result = auction_mail_imap_reply($socket, 'A002');
    if ($result === null || !$result[0]) return null;

    foreach ($result[1] as $line) {
      if (preg_match('/^\* STATUS (?:"INBOX"|INBOX) \([^)]*\bUNSEEN\s+([0-9]+)\b[^)]*\)/i', $line, $m)) {
        $count = filter_var($m[1], FILTER_VALIDATE_INT);
        return is_int($count) && $count >= 0 ? $count : null;
      }
    }
    return null;
  } catch (Throwable $ignored) {
    return null;
  } finally {
    // No server text or credentials are recorded. Even failure never leaks status.
    @fwrite($socket, "A003 LOGOUT\r\n");
    @fclose($socket);
  }
}
