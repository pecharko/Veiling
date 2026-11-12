<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$script=file_get_contents($root.'/dev/preview/import_download_artists.php');
$ignore=file_get_contents($root.'/.gitignore');
$page=file_get_contents($root.'/catalogus-public/kunstenaar.php');
if (!is_string($script) || !is_string($ignore) || !is_string($page)) throw new RuntimeException('Missing preview importer sources.');
foreach ([
  "getenv('LOCAL_PREVIEW_ISOLATED') !== '1'",
  "cfg('setup_key') !== 'local-preview-only-nonproduction'",
  "'preview-db'",
  "'auction_test'",
  "'example.invalid'",
  "'mila_vermeer'",
  "'elias_moreau'",
  "'noor_azevedo'",
  'store_image(',
  'SELECT id,seller_id FROM lots WHERE lot_number=?',
  'SELECT id,path FROM seller_images WHERE seller_id=?',
  'INSERT INTO seller_images',
  'INSERT INTO lots',
  '901201',
  '901206',
  '$pdo->rollBack()',
] as $token) {
  if (!str_contains($script,$token)) throw new RuntimeException('Preview importer missing safety or fixture contract: '.$token);
}
if (!str_contains($ignore,'dev/preview/local-artists/')) throw new RuntimeException('Local artist originals must be gitignored.');
if (!str_contains($page,'uploads/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}')) throw new RuntimeException('Public artist page must accept optimized image paths.');
echo "PASS local-only artist importer safety, fixed JPG slots, and public photo links.\n";
