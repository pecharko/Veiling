<?php
declare(strict_types=1);

// Offline regression for the artist hyperlink feedback in the lot editor.
$source = file_get_contents(__DIR__ . '/../catalogus-public/admin/kavel.php');
if ($source === false) throw new RuntimeException('Lot editor not readable');
$checks = [
  'URL input supports bare www-addresses' => 'type="text" inputmode="url" name="artist_url"',
  'www URL completion hint' => 'dit wordt automatisch https://www.marf.be',
  'www HTTPS normalizer' => 'function normalizedArtistLink(value)',
  'Completion on leaving the field' => "link.addEventListener('blur'",
  'Completion on submitting form' => 'completeArtistLink();',
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
