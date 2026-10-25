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
if (!str_contains($bootstrap, "'rollen.php', 'logout.php'")) throw new RuntimeException('Help must be accessible to assistants and veilingmeesters');
echo "PASS role menu and read-only documentation contract\n";
