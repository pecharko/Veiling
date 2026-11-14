<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/lot_artist.php';
function assert_artist(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}
$limit = str_repeat('A', 50);
// The generic legacy artist URL helper still accepts up to 250 characters.
// The central seller record enforces the public artist-name cap of 50.
require_once dirname(__DIR__).'/private/lot_sellers.php';
$artistInput=[
  'seller_type'=>'kunstenaar','name'=>'Voorbeeldmaker','email'=>'maker@example.invalid',
  'street'=>'Voorbeeldstraat','house_number'=>'1','postal_code'=>'9160',
  'municipality'=>'Lokeren','artist_name'=>$limit,'artist_url'=>'https://example.org'
];
assert_artist(seller_clean($artistInput)['artist_name']===$limit, '50-character seller artist name accepted');
try {
  seller_clean(array_replace($artistInput,['artist_name'=>$limit.'B']));
  throw new RuntimeException('51 characters unexpectedly accepted');
} catch (InvalidArgumentException $e) {
  assert_artist(str_contains($e->getMessage(),'artist name'), 'Central seller name length limit reported');
}
$legacy = lot_artist_markup(['artist_name' => $limit . 'B', 'artist_url' => 'https://example.org/']);
assert_artist($legacy === $limit . 'B', 'Unpublished artist names remain plain text even with legacy URLs');
$admin = file_get_contents(dirname(__DIR__) . '/catalogus-public/admin/kavel.php');
$css = file_get_contents(dirname(__DIR__) . '/catalogus-public/assets/catalogus.css');
$catalog = file_get_contents(dirname(__DIR__) . '/catalogus-public/index.php');
$detail = file_get_contents(dirname(__DIR__) . '/catalogus-public/kavel.php');
$sellerEditor = file_get_contents(dirname(__DIR__) . '/catalogus-public/admin/verkoper.php');
assert_artist(str_contains($sellerEditor, 'name="artist_name" maxlength="50"'), 'Artist name limit belongs to central seller editor');
assert_artist(!str_contains($admin, 'name="artist_name"'), 'Lot editor has no duplicate artist name field');
assert_artist(!str_contains($admin, 'artist.setCustomValidity(tooLong ? artistMessage :'), 'No redundant JavaScript length error');
assert_artist(str_contains($css, '.lot-artist-name{display:block;flex:0 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap'), 'Single-line truncation');
assert_artist(str_contains($catalog, 'class="lot-artist-name" title='), 'Catalog accessible full artist name');
assert_artist(str_contains($detail, 'class="lot-artist-name" title='), 'Detail accessible full artist name');
echo "PASS: artist 50-character limit and responsive one-line preview.\n";
