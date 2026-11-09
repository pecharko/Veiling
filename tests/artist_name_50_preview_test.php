<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/lot_artist.php';
function assert_artist(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}
$limit = str_repeat('A', 50);
assert_artist(lot_artist_validate($limit, '') === [$limit, ''], '50 characters must be accepted');
try {
  lot_artist_validate($limit . 'B', '');
  throw new RuntimeException('51 characters unexpectedly accepted');
} catch (InvalidArgumentException $e) {
  assert_artist(str_contains($e->getMessage(), 'maximaal 50 tekens'), 'Expected Dutch length error');
}
$legacy = lot_artist_markup(['artist_name' => $limit . 'B', 'artist_url' => 'https://example.org/']);
assert_artist(str_contains($legacy, '<a href="https://example.org/"'), 'Old long names retain valid artist links');
$admin = file_get_contents(dirname(__DIR__) . '/catalogus-public/admin/kavel.php');
$css = file_get_contents(dirname(__DIR__) . '/catalogus-public/assets/catalogus.css');
$catalog = file_get_contents(dirname(__DIR__) . '/catalogus-public/index.php');
$detail = file_get_contents(dirname(__DIR__) . '/catalogus-public/kavel.php');
assert_artist(str_contains($admin, "const artistMessage = 'De naam van de kunstenaar mag maximaal 50 tekens bevatten.'"), 'Inline editor error');
assert_artist(str_contains($admin, "artist.setCustomValidity(tooLong ? artistMessage : '')"), 'Editor blocks invalid value');
assert_artist(str_contains($css, '.lot-artist-name{display:block;flex:0 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap'), 'Single-line truncation');
assert_artist(str_contains($catalog, 'class="lot-artist-name" title='), 'Catalog accessible full artist name');
assert_artist(str_contains($detail, 'class="lot-artist-name" title='), 'Detail accessible full artist name');
echo "PASS: artist 50-character limit and responsive one-line preview.\n";
