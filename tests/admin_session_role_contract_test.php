<?php
declare(strict_types=1);
$root = dirname(__DIR__);
function expectRole(bool $ok, string $label): void {
  if (!$ok) throw new RuntimeException('Rolbeveiliging: '.$label);
}
$bootstrap = file_get_contents($root.'/private/bootstrap.php');
$auction = file_get_contents($root.'/catalogus-public/admin/veiling-organisatie.php');
$switch = file_get_contents($root.'/catalogus-public/admin/rol-wissel.php');
$users = file_get_contents($root.'/catalogus-public/admin/gebruikers.php');
$login = file_get_contents($root.'/catalogus-public/admin/login.php');
$migration = file_get_contents($root.'/database/veilingmeester_role_migration.sql');
expectRole(str_contains($bootstrap, 'function effective_admin_role()'), 'effective role');
expectRole(str_contains($bootstrap, "['administrator', 'assistant', 'veilingmeester']"), 'exact role allowlist');
expectRole(str_contains($bootstrap, "stored_admin_role() === 'administrator'"), 'original admin only');
expectRole(str_contains($bootstrap, "'rol-wissel.php'"), 'role switch direct allowlist');
expectRole(str_contains($bootstrap, '$roleSwitchNav'), 'switch menu retained');
expectRole(str_contains($bootstrap, 'effective_admin_role() !== \'administrator\''), 'admin pages enforce simulated role');
expectRole(str_contains($auction, 'if (!$canEdit)'), 'POST blocked for assistants');
expectRole(str_contains($auction, 'http_response_code(403)'), '403 for assistant POST');
expectRole(str_contains($auction, '<?php if ($canEdit): ?>'), 'edit UI absent for viewer');
expectRole(str_contains($switch, 'check_csrf()'), 'CSRF required');
expectRole(str_contains($switch, 'stored_admin_role() !== \'administrator\''), 'role switching only original admin');
expectRole(str_contains($switch, 'session_regenerate_id(true)'), 'renew session ID on role switch');
expectRole(str_contains($login, "unset(\$_SESSION['admin_view_role'])"), 'login restores real admin role');
expectRole(str_contains($users, "'veilingmeester'"), 'user role select supports auctioneer');
expectRole(str_contains($migration, "ENUM('administrator','assistant','veilingmeester')"), 'manual migration');
echo "PASS role permissions, view-only auction screen, original admin switch and session reset\n";
