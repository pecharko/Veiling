<?php
declare(strict_types=1);
$backup = file_get_contents(dirname(__DIR__).'/catalogus-public/admin/backup.php');
$reset = file_get_contents(dirname(__DIR__).'/private/catalog_reset.php');
$editor = file_get_contents(dirname(__DIR__).'/catalogus-public/admin/kavel.php');
foreach ([
  "require_administrator()",
  "check_csrf()",
  "WIS ALLE KAVELS EN HERSTART OP 1",
  "catalog_data_lock(\$pdo)",
  "reset_catalog_lots(\$pdo)"
] as $needle) {
  if (!str_contains($backup, $needle)) throw new RuntimeException('Reset protection missing: '.$needle);
}
foreach ([
  '$pdo->beginTransaction()',
  "DELETE FROM lot_images",
  "DELETE FROM lots",
  "DELETE FROM issued_lot_numbers",
  '$pdo->commit()',
  '$pdo->rollBack()'
] as $needle) {
  if (!str_contains($reset, $needle)) throw new RuntimeException('Atomic reset requirement missing: '.$needle);
}
foreach ([
  "foreach(\$result['paths'] as \$path)",
  "preg_match('~^uploads/",
  "is_file(dirname(__DIR__).'/'.\$path)",
  "!@unlink(dirname(__DIR__).'/'.\$path)",
  "fotobestanden konden niet worden verwijderd"
] as $needle) {
  if (!str_contains($backup, $needle)) throw new RuntimeException('Safe photo cleanup missing: '.$needle);
}
$commit = strpos($reset, '$pdo->commit()');
$cleanup = strpos($backup, "foreach(\$result['paths'] as \$path)");
if ($commit === false || $cleanup === false) throw new RuntimeException('Commit and photo cleanup must both exist.');
// The cleanup is only reachable after reset_catalog_lots returns after commit.
if (!str_contains($backup, "try{\$result=reset_catalog_lots(\$pdo);}")) {
  throw new RuntimeException('Photo cleanup must follow the committed reset result.');
}
if (str_contains($reset, 'TRUNCATE')) throw new RuntimeException('TRUNCATE cannot be rolled back in MySQL.');
if (!str_contains($editor, 'compact_auction_numbers($pdo)')) throw new RuntimeException('Auction deletion must resequence.');
echo "PASS catalog reset source contract\n";
