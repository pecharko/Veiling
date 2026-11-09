<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$admin = file_get_contents($root . '/catalogus-public/admin/kavel.php');
$css = file_get_contents($root . '/catalogus-public/assets/catalogus.css');
$catalog = file_get_contents($root . '/catalogus-public/index.php');
$detail = file_get_contents($root . '/catalogus-public/kavel.php');
function artist_assert(bool $ok, string $reason): void {
  if (!$ok) throw new RuntimeException($reason);
}
artist_assert(str_contains($admin, "mb_strlen(\$artistName, 'UTF-8') > 50"), 'Server artist limit missing');
artist_assert(str_contains($admin, 'De naam van de kunstenaar mag maximaal 50 tekens bevatten.'), 'Artist warning missing');
artist_assert(str_contains($admin, "['artist_name', 'lot-artist-error'"), 'Live artist warning missing');
artist_assert(str_contains($admin, "input.setCustomValidity(tooLong ? message : '')"), 'Browser validation missing');
artist_assert(str_contains($css, '.lot-artist-name{display:block;flex:0 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap'), 'Single-line ellipsis missing');
artist_assert(str_contains($css, '.detail-page-grid{grid-template-columns:minmax(0,1.08fr) minmax(0,.92fr)}'), 'Shrinkable detail columns missing');
artist_assert(str_contains($catalog, 'class="lot-artist-name" title='), 'Catalog must preserve full name on hover');
artist_assert(str_contains($detail, 'class="lot-artist-name" title='), 'Detail must preserve full name on hover');
echo "PASS: artist field 50 characters, inline warning and responsive single-line credit.\n";
