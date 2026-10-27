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
// Name/profile and creator-label regressions run in the existing PHP CI job.
require_once $root.'/private/admin_names.php';
function name_contract(bool $condition, string $description): void {
  if (!$condition) throw new RuntimeException($description);
}
name_contract(admin_validated_name('  Petra   Janssens ') === 'Petra Janssens', 'Account name normalization');
name_contract(admin_validated_name('Élodie Van den Berg') === 'Élodie Van den Berg', 'Unicode names accepted');
name_contract(admin_name_label('  ') === 'Naam nog niet ingevuld', 'Historic accounts show no invented name');
name_contract(admin_name_label('Pecharko') === 'Pecharko', 'Creator label uses the account name');
foreach (['', str_repeat('a',121), "Regel\nbreuk"] as $invalid) {
  try { admin_validated_name($invalid); throw new RuntimeException('Invalid name accepted'); }
  catch (InvalidArgumentException $expected) {}
}
$lot=file_get_contents($root.'/catalogus-public/admin/kavel.php');
$snapshot=file_get_contents($root.'/private/snapshots.php');
$recovery=file_get_contents($root.'/private/restore_diagnosis.php');
$nameMigration=file_get_contents($root.'/database/admin_name_migration.sql');
foreach ([
  [$nameMigration, "ADD COLUMN name VARCHAR(120) NOT NULL DEFAULT ''", 'Explicit non-destructive names migration'],
  [$users, 'admin_validated_name(', 'Create/update check account name server-side'],
  [$users, 'name="name"', 'Create/update form has name input'],
  [$users, 'SET name=?, email=?, role=?', 'Names persist in administrator updates'],
  [$users, 'data-search=', 'User list can be searched by name'],
  [$lot, 'SELECT name FROM admins WHERE id=?', 'Creator is resolved by name rather than email'],
  [$lot, 'admin_name_label((string)$creatorName)', 'Creator missing name is labelled safely'],
  [$snapshot, "'admins'=>['id','name','email'", 'Snapshots include administrator name'],
  [$snapshot, "col==='name' ? ''", 'Legacy snapshot restore defaults name to empty'],
  [$recovery, "if(!array_key_exists('name', \$row))", 'Read-only diagnosis handles legacy snapshot names'],
] as [$contents,$needle,$reason]) {
  name_contract(str_contains($contents,$needle),$reason);
}
echo "PASS admin name, migration, creator and snapshot contracts\n";

