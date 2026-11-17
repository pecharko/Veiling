<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';

require_no_index();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  header('Allow: GET');
  http_response_code(405);
  echo '{"available":false}';
  exit;
}
require_auth();
if (!in_array(effective_admin_role(), ['administrator', 'veilingmeester'], true)) {
  http_response_code(403);
  echo '{"available":false}';
  exit;
}

require_once dirname(__DIR__, 2) . '/private/mail_unread.php';
$config = cfg('mail_unread');
if (!auction_mail_unread_configured($config)) {
  // Explicitly distinguish "not yet linked" from an IMAP/network failure.
  // Only already-authorized admins/auctioneers can read this status.
  echo '{"available":false,"reason":"not_configured"}';
  exit;
}
$count = auction_mail_unread_count($config);
if ($count === null) {
  // An unconfigured or inaccessible mailbox is not equivalent to zero unread.
  http_response_code(503);
  echo '{"available":false}';
  exit;
}
echo json_encode(['available' => true, 'unread' => $count], JSON_THROW_ON_ERROR);
