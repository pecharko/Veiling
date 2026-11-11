<?php
declare(strict_types=1);

// Artist hyperlinks are now entered and validated in the central seller editor.
$lotEditor=file_get_contents(__DIR__ . '/../catalogus-public/admin/kavel.php');
$sellerEditor=file_get_contents(__DIR__ . '/../catalogus-public/admin/verkoper.php');
$sellerHelpers=file_get_contents(__DIR__ . '/../private/lot_sellers.php');
if (!is_string($lotEditor)||!is_string($sellerEditor)||!is_string($sellerHelpers)) throw new RuntimeException('Editors not readable');
if (str_contains($lotEditor,'name="artist_url"') || !str_contains($lotEditor,'name="seller_id"'))
  throw new RuntimeException('Lot editor must use seller relationship only');
if (!str_contains($sellerEditor,'name="artist_url"') || !str_contains($sellerHelpers,'lot_artist_validate('))
  throw new RuntimeException('Seller editor must validate artist links');
require_once __DIR__ . '/../private/lot_artist.php';
foreach (['javascript:alert(1)', 'ftp://example.org', 'https://user:pass@example.org', 'https://example.org/a b', 'not-a-url', 'www.marf.be@evil.org', 'www.marf.be space', 'www.marf.be\\evil'] as $bad) {
  try {
    lot_artist_validate('Naam kunstenaar', $bad);
    throw new RuntimeException("Accepted invalid URL: $bad");
  } catch (InvalidArgumentException) {
    // Expected.
  }
}
foreach (['https://example.org/artist' => 'https://example.org/artist', 'http://example.org/' => 'http://example.org/', 'www.marf.be' => 'https://www.marf.be', 'www.marf.be/kunst' => 'https://www.marf.be/kunst'] as $input => $expected) {
  [, $valid] = lot_artist_validate('Naam kunstenaar', $input);
  if ($valid !== $expected) throw new RuntimeException('Unexpected URL normalization');
}
echo "Artist hyperlink validation contract OK\n";
