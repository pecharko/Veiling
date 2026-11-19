<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$bootstrap = file_get_contents($root . '/private/bootstrap.php');
$help = file_get_contents($root . '/catalogus-public/admin/rollen.php');
$css = file_get_contents($root . '/catalogus-public/admin/admin.css');
foreach (['rollen.php', 'admin-active-role', 'Actieve rol:', 'Rollen &amp; rechten'] as $needle) {
  if (!str_contains($bootstrap, $needle)) throw new RuntimeException('Missing menu role indicator or help: ' . $needle);
}
foreach (['require_auth();', 'Administrator', 'Assistent', 'Veilingmeester', 'Veilingvolgorde wijzigen', 'Tijdelijk van rol wisselen'] as $needle) {
  if (!str_contains($help, $needle)) throw new RuntimeException('Missing roles guidance: ' . $needle);
}
if (!str_contains($css, '.admin-active-role')) throw new RuntimeException('Missing role indicator styling');
// The allowed-route list can gain further permitted endpoints (e.g. explicit
// session renewal) without changing access to help or logout.
foreach (["'rollen.php'", "'mail-ongelezen.php'", "'logout.php'", "'sessie-verlengen.php'"] as $route) {
  if (!str_contains($bootstrap, $route)) throw new RuntimeException('Missing allowed admin route: ' . $route);
}
// Run the dedicated OVH Outlook menu guard on existing role-menu CI paths.
require __DIR__ . '/admin_outlook_owa_menu_contract_test.php';
require __DIR__ . '/admin_mail_unread_contract_test.php';
require __DIR__ . '/admin_mail_tls_probe_contract_test.php';
echo "PASS role menu and read-only documentation contract\n";
