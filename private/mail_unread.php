<?php
declare(strict_types=1);

/**
 * Read-only inbox counter for the optional OVH MX Plan integration.
 * Requires PHP ext-imap and private config key 'mail_unread' containing
 * an enabled flag, the fixed mailbox username and its password.
 * Never print secrets, message headers, sender details or IMAP errors.
 */
function auction_mail_unread_count(mixed $config): ?int {
  if (!is_array($config) || ($config['enabled'] ?? false) !== true) return null;
  if (!function_exists('imap_open') || !function_exists('imap_status')) return null;
  if (($config['user'] ?? null) !== 'veiling@41lokeren.be') return null;
  $password = $config['password'] ?? null;
  if (!is_string($password) || $password === '' || strlen($password) > 1024
      || strpbrk($password, "\r\n\0") !== false) return null;

  // Server name and endpoint are fixed: no URL/hostname inputs from clients.
  $mailbox = '{imap.mail.ovh.ca:993/imap/ssl/readonly}INBOX';
  $stream = null;
  try {
    // OP_READONLY makes this connection incapable of marking mail as seen.
    $stream = @imap_open($mailbox, 'veiling@41lokeren.be', $password, OP_READONLY, 1, [
      'DISABLE_AUTHENTICATOR' => 'GSSAPI',
    ]);
    if ($stream === false) return null;
    $status = @imap_status($stream, $mailbox, SA_UNSEEN);
    if (!is_object($status) || !isset($status->unseen)) return null;
    $count = (int)$status->unseen;
    return $count >= 0 ? $count : null;
  } catch (Throwable $ignored) {
    return null; // No IMAP error text or credentials in logs/responses.
  } finally {
    if ($stream !== null && $stream !== false) @imap_close($stream);
    if (function_exists('imap_errors')) @imap_errors(); // Drain PHP IMAP's internal error buffer.
  }
}
