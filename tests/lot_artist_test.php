<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/lot_artist.php';
function artist_check(bool $ok, string $label): void {
  if (!$ok) throw new RuntimeException($label);
}
artist_check(lot_artist_validate('', '') === ['', ''], 'Empty fields optional');
artist_check(lot_artist_validate('  Piet  ', ' https://example.org/artist?a=1&b=2 ') ===
    ['Piet','https://example.org/artist?a=1&b=2'], 'Trim valid fields');
foreach ([
  ['', 'https://example.org/'],
  ['Piet', 'javascript:alert(1)'],
  ['Piet', '//example.org/'],
  ['Piet', 'data:text/html,x'],
  ['Piet', 'https://example.org/space here'],
  ['Piet', 'https://user:pass@example.org/'],
  ['Piet', 'https://example.org/'.str_repeat('x', 2050)],
] as [$name, $url]) {
  try {
    lot_artist_validate($name, $url);
    throw new RuntimeException('Unsafe artist metadata accepted: '.$url);
  } catch (InvalidArgumentException $expected) {
    // Deliberately rejected before SQL writes.
  }
}
artist_check(lot_artist_markup(['artist_name'=>''])==='', 'No empty artist label');
artist_check(lot_artist_markup(['artist_name'=>'Jan <Jans>', 'artist_url'=>''])==='Jan &lt;Jans&gt;', 'No URL, plain escaped name');
$linked=lot_artist_markup(['artist_name'=>'Jan & Co', 'artist_url'=>'https://example.org/a?x=1&y=2']);
artist_check(str_contains($linked, '>Jan &amp; Co</a>'), 'Name is linked');
artist_check(str_contains($linked,'href="https://example.org/a?x=1&amp;y=2"'), 'URL escaped');
artist_check(str_contains($linked,'rel="noopener noreferrer"'), 'Safe external link');
artist_check(!str_contains(lot_artist_markup(['artist_name'=>'Piet','artist_url'=>'javascript:alert(1)']),'<a'), 'Invalid legacy URL cannot create link');
$root=dirname(__DIR__);
foreach (['catalogus-public/pecharko.php','catalogus-public/assets/pecharko.css'] as $deleted) {
  artist_check(!file_exists($root.'/'.$deleted), 'Obsolete Pecharko site asset must be absent: '.$deleted);
}
foreach (['catalogus-public/index.php', 'catalogus-public/kavel.php'] as $page) {
  $source=file_get_contents($root.'/'.$page);
  artist_check(is_string($source) && str_contains($source, 'lot_artist_markup($lot)'),
    'Public page must display linked artist name: '.$page);
}
$editor=file_get_contents($root.'/catalogus-public/admin/kavel.php');
artist_check(is_string($editor) && str_contains($editor,'lot_artist_validate(')
  && str_contains($editor,'name="artist_name"') && str_contains($editor,'name="artist_url"'),
  'Editor must validate and show both artist fields');
echo "PASS lot artist validation and safe public markup\n";
