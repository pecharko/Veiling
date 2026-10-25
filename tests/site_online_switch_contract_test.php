<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$files = [
  'catalogus-public/index.php',
  'catalogus-public/kavel.php',
  'catalogus-public/privacy.php'
];
foreach ($files as $file) {
  $source = file_get_contents($root . '/' . $file);
  if ($source === false || !str_contains($source, "require_public_site_online();")) {
    throw new RuntimeException('Missing offline gate: ' . $file);
  }
}
$bootstrap = file_get_contents($root . '/private/bootstrap.php');
$admin = file_get_contents($root . '/catalogus-public/admin/instellingen.php');
$migration = file_get_contents($root . '/database/site_online_migration.sql');
if (!str_contains($bootstrap, "function require_public_site_online(): void")
 || !str_contains($bootstrap, "http_response_code(503)")
 || !str_contains($bootstrap, "is_site_online")
 || !str_contains($bootstrap, "?? 1")
 || !str_contains($admin, 'name="is_site_online"')
 || !str_contains($admin, "'is_site_online' => isset(\$_POST['is_site_online']) ? 1 : 0")
 || !str_contains($migration, 'DEFAULT 1')) {
  throw new RuntimeException('Website visibility guard, admin toggle or safe default missing');
}
$snap = file_get_contents($root . '/private/snapshots.php');
if (!str_contains($snap, "'is_published','is_site_online','updated_at'")
 || !str_contains($snap, "\$table==='settings' && \$col==='is_site_online' ? 1")
 || !str_contains($snap, "\$t==='settings' && array_values(array_diff(SNAP_INSERT[\$t],array_keys(\$row)))===['is_site_online']")) {
  throw new RuntimeException('Snapshot creation and backward-compatible restore must preserve website online state');
}
echo "PASS Website online global switch contract\n";
