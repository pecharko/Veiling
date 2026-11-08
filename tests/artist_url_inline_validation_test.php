<?php
declare(strict_types=1);

// Offline regression for the artist hyperlink feedback in the lot editor.
$source = file_get_contents(__DIR__ . '/../catalogus-public/admin/kavel.php');
if ($source === false) throw new RuntimeException('Lot editor not readable');
$checks = [
  'URL input stays native browser validated' => 'type="url" name="artist_url"',
  'Full URL instruction' => 'Gebruik een volledige link die begint met https:// of http://',
  'Inline validation function' => 'function validateArtistLink()',
  'No credential-bearing artist URLs' => 'url.username || url.password',
  'Reject unsupported schemes' => "['http:', 'https:'].includes(url.protocol)",
  'Link needs an artist name' => 'if (value && !name.value.trim())',
  'Clear stale custom validity' => 'link.setCustomValidity(message)',
  'Announce invalid input' => "link.setAttribute('aria-invalid'",
  'Validate while typing' => "link.addEventListener('input', validateArtistLink)",
  'Validate when name changes' => "name.addEventListener('input', validateArtistLink)",
  'Prevent invalid submission' => 'event.preventDefault()',
];
foreach ($checks as $description => $snippet) {
  if (!str_contains($source, $snippet)) {
    fwrite(STDERR, "FAIL: $description\n");
    exit(1);
  }
}
require_once __DIR__ . '/../private/lot_artist.php';
foreach (['javascript:alert(1)', 'ftp://example.org', 'https://user:pass@example.org', 'https://example.org/a b', 'not-a-url'] as $bad) {
  try {
    lot_artist_validate('Naam kunstenaar', $bad);
    throw new RuntimeException("Accepted invalid URL: $bad");
  } catch (InvalidArgumentException) {
    // Expected.
  }
}
foreach (['https://example.org/artist', 'http://example.org/'] as $good) {
  [, $valid] = lot_artist_validate('Naam kunstenaar', $good);
  if ($valid !== $good) throw new RuntimeException('Valid URL mutated');
}
echo "Artist hyperlink validation contract OK\n";
