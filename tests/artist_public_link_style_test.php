<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/private/lot_artist.php';

function artist_style_assert(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

$card = file_get_contents(__DIR__ . '/../catalogus-public/index.php');
$detail = file_get_contents(__DIR__ . '/../catalogus-public/kavel.php');
$css = file_get_contents(__DIR__ . '/../catalogus-public/assets/catalogus.css');

artist_style_assert(is_string($card) && is_string($detail), 'Cataloguskaart en kaveldetail zijn leesbaar');
artist_style_assert(str_contains($card, '<span class="lot-artist-label">Kunstenaar:</span> <span class="lot-artist-name" title="<?=e($lot[\'artist_name\'])?>"><?=e($lot[\'artist_name\'])?></span>'), 'Catalogus toont kunstenaar uitsluitend als veilige tekst');
artist_style_assert(!str_contains($card, '<?=lot_artist_markup($lot)?>'), 'Catalogus mag geen artiestenlink genereren');
artist_style_assert(str_contains($detail, '<span class="lot-artist-label">Kunstenaar:</span> <span class="lot-artist-name" title="<?=e($lot[\'artist_name\'])?>"><?=lot_artist_markup($lot)?></span>'), 'Kaveldetail behoudt kunstenaarslink');
artist_style_assert(str_contains($card, 'class="lot-artist"'), 'Cataloguskaart gebruikt een aparte kunstenaarcontainer');
artist_style_assert(str_contains($detail, 'class="detail-artist"'), 'Kaveldetail gebruikt een aparte kunstenaarcontainer');
artist_style_assert(is_string($css) && str_contains($css, '.lot-artist-name a{color:#1856a3;text-decoration:underline'), 'Kunstenaarslink heeft blauwe onderlijnde stijl');
artist_style_assert(str_contains($css, '.lot-artist-name a:hover') && str_contains($css, '.lot-artist-name a:focus-visible'), 'Hover en toetsenbordfocus blijven zichtbaar');
artist_style_assert(str_contains($css, '.lot-artist-label{'), 'Het label heeft een eigen stijl');
artist_style_assert(str_contains($css, '.lot-artist,.detail-artist{display:flex') && str_contains($css, 'text-overflow:ellipsis;white-space:nowrap'), 'Label en kunstenaarsnaam blijven op één regel met afkapping');

$linked = lot_artist_markup(['artist_name'=>'Marf', 'artist_url'=>'https://www.marf.be/']);
artist_style_assert(str_contains($linked, 'href="https://www.marf.be/"'), 'Een geldige kunstenaarslink blijft klikbaar');
artist_style_assert(str_contains($linked, 'rel="noopener noreferrer"'), 'Veilige externe-link-attributen behouden');
artist_style_assert(lot_artist_markup(['artist_name'=>'Marf', 'artist_url'=>'']) === 'Marf', 'Zonder URL verschijnt gewone tekst');
artist_style_assert(!str_contains(lot_artist_markup(['artist_name'=>'Marf','artist_url'=>'javascript:alert(1)']), '<a'), 'Ongeldige links blijven niet klikbaar');

echo "PASS artist attribution layout, accessibility and URL safety\n";
