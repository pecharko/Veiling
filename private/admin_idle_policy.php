<?php
declare(strict_types=1);

/** Issue #23: pure policy helpers; no DB, files, requests or session writes. */
const ADMIN_IDLE_SECONDS = 60 * 60;

function admin_idle_expired(?int $lastActivity, int $now): bool {
  if ($lastActivity === null) return false; // Existing sessions start their clock on next authenticated action.
  return $lastActivity > $now + 60 || $now - $lastActivity >= ADMIN_IDLE_SECONDS;
}
function admin_idle_expiry(?int $lastActivity, int $now): int {
  return ($lastActivity ?? $now) + ADMIN_IDLE_SECONDS;
}
function admin_idle_should_touch(string $scriptName): bool {
  // Passive unread-mail polling every two minutes is not user activity.
  return basename($scriptName) !== 'mail-ongelezen.php';
}
