<?php
declare(strict_types=1);
// Contract guard for the separate migration and both login/session enforcement.
$root=dirname(__DIR__);
$schema=file_get_contents($root.'/database/admin_active_migration.sql');
$login=file_get_contents($root.'/catalogus-public/admin/login.php');
$bootstrap=file_get_contents($root.'/private/bootstrap.php');
$users=file_get_contents($root.'/catalogus-public/admin/gebruikers.php');
foreach ([
  [$schema, 'is_active TINYINT(1) NOT NULL DEFAULT 1', 'Default accounts active'],
  [$login, 'SELECT id,password_hash,is_active FROM admins', 'Login reads account state'],
  [$login, "(int)\$admin['is_active'] === 1", 'Login blocks disabled accounts'],
  [$bootstrap, 'SELECT password_hash, is_active FROM admins', 'Every session request checks account state'],
  [$bootstrap, "(int)(\$account['is_active'] ?? 0) !== 1", 'Disabled sessions revoked'],
  [$users, "if (\$role === 'administrator') throw", 'Admin disable rejected server-side'],
  [$users, 'check_csrf();', 'CSRF enforced'],
  [$users, "password_verify(\$currentPassword", 'Admin action requires password'],
] as [$contents,$needle,$reason]) {
  if (!str_contains($contents,$needle)) throw new RuntimeException($reason);
}
echo "PASS inactive account access guards\n";
