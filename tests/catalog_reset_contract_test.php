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
if (!str_contains($backup, "reset_catalog_photo_files(\$result['paths'], dirname(__DIR__))")) throw new RuntimeException('Post-commit photo cleanup call missing');
if (!str_contains($reset, "preg_match('~^uploads/") || !str_contains($reset, "!@unlink(\$file)")) throw new RuntimeException('Safe photo cleanup guard missing');
if (str_contains($reset, 'TRUNCATE')) throw new RuntimeException('TRUNCATE cannot be rolled back in MySQL.');
if (!str_contains($editor, 'compact_auction_numbers($pdo)')) throw new RuntimeException('Auction deletion must resequence.');
$snap = file_get_contents(dirname(__DIR__).'/private/snapshots.php');
if (!str_contains($snap, "INSERT IGNORE INTO issued_lot_numbers (lot_number) SELECT lot_number FROM lots")) throw new RuntimeException('Restore must preserve issued lot number history');
echo "PASS catalog reset source contract\n";
