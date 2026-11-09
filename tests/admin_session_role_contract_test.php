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
expectRole(str_contains($bootstrap, 'function admin_role_switch_targets(string $stored): array'), 'switch targets bound to original role');
expectRole(str_contains($bootstrap, "'veilingmeester' => ['veilingmeester', 'assistant']"), 'auctioneer cannot choose administrator');
expectRole(str_contains($bootstrap, 'in_array($override, admin_role_switch_targets($stored), true)'), 'effective role constrained to stored role');
expectRole(str_contains($bootstrap, 'admin_role_switch_targets(stored_admin_role()) !== []'), 'switch available in assistant view for original auctioneer');
expectRole(str_contains($bootstrap, "'rol-wissel.php'"), 'role switch direct allowlist');
expectRole(str_contains($bootstrap, '$roleSwitchNav'), 'switch menu retained');
expectRole(str_contains($bootstrap, 'effective_admin_role() !== \'administrator\''), 'admin pages enforce simulated role');
expectRole(str_contains($auction, 'if (!$canEdit)'), 'POST blocked for assistants');
expectRole(str_contains($auction, 'http_response_code(403)'), '403 for assistant POST');
expectRole(str_contains($auction, '<?php if ($canEdit): ?>'), 'edit UI absent for viewer');
expectRole(str_contains($switch, 'check_csrf()'), 'CSRF required');
expectRole(str_contains($switch, 'admin_role_switch_targets($storedRole)'), 'role switch uses original account role');
expectRole(str_contains($switch, 'in_array($role, $allowedRoles, true)'), 'POST cannot elevate auctioneer to administrator');
expectRole(str_contains($switch, "http_response_code(403)"), 'forbidden switch rejected');
expectRole(str_contains($switch, 'session_regenerate_id(true)'), 'renew session ID on role switch');
expectRole(str_contains($login, "unset(\$_SESSION['admin_view_role'])"), 'login restores real admin role');
expectRole(str_contains($users, "'veilingmeester'"), 'user role select supports auctioneer');
expectRole(str_contains($migration, "ENUM('administrator','assistant','veilingmeester')"), 'manual migration');
echo "PASS role permissions, veilingmeester assistant switch, no admin escalation, and session reset\n";
