<?php
declare(strict_types=1);

/** Validate optional artist metadata before storing it on a lot. */
function lot_artist_validate(string $name, string $link): array {
  $name = trim($name);
  $link = trim($link);
  if (mb_strlen($name, 'UTF-8') > 50) {
    throw new InvalidArgumentException('De naam van de kunstenaar mag maximaal 50 tekens bevatten.');
  }
  if ($link === '') return [$name, ''];
  // A familiar www-address is completed to an HTTPS hyperlink on save.
  // Never prepend a scheme to arbitrary or explicitly unsupported input.
  if (preg_match('~^www\\.[a-z0-9-]+(?:\\.[a-z0-9-]+)+(?::[0-9]{1,5})?(?:[/?\\#][^\\x00-\\x20\\x7f]*)?$~iD', $link)) {
    $link = 'https://' . $link;
  }
  if ($name === '') {
    throw new InvalidArgumentException('Vul eerst de naam van de kunstenaar in voordat je een hyperlink toevoegt.');
  }
  if (strlen($link) > 2048 || preg_match('/[\x00-\x20\x7f]/', $link)) {
    throw new InvalidArgumentException('De hyperlink van de kunstenaar is ongeldig of te lang.');
  }
  $parts = parse_url($link);
  if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
      || !in_array(strtolower($parts['scheme']), ['https', 'http'], true)
      || $parts['host'] === ''
      || isset($parts['user']) || isset($parts['pass'])
      || filter_var($link, FILTER_VALIDATE_URL) === false) {
    throw new InvalidArgumentException('Gebruik een volledige http- of https-link voor de kunstenaar.');
  }
  return [$name, $link];
}

/**
 * The name, not an icon or the entire kavel, is the clickable link.
 * Untrusted legacy rows fail closed to plain escaped text.
 */
function lot_artist_markup(array $lot): string {
  $name = trim((string)($lot['artist_name'] ?? ''));
  if ($name === '') return '';
  $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $url = trim((string)($lot['artist_url'] ?? ''));
  if ($url === '') return $safeName;
  try {
    [, $url] = lot_artist_validate(mb_substr($name, 0, 50, 'UTF-8'), $url);
  } catch (InvalidArgumentException) {
    return $safeName;
  }
  $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  return '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">' . $safeName . '</a>';
}
