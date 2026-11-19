<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/private/bootstrap.php';
require_no_index();
admin_session();
if (!is_post()) {
  header('Allow: POST');
  http_response_code(405);
  exit('POST vereist.');
}
check_csrf();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['ok' => true, 'expires_at' => admin_idle_expires_at()], JSON_UNESCAPED_SLASHES);
