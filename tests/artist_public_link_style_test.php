<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/lot_artist.php';

function artist_style_assert(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

$card = file_get_contents(__DIR__ . '/../catalogus-public/index.php');
$detail = file_get_contents(__DIR__ . '/../catalogus-public/kavel.php');
$css = file_get_contents(__DIR__ . '/../catalogus-public/assets/catalogus.css');

foreach (['Cataloguskaart' => $card, 'Kaveldetail' => $detail] as $page => $source) {
  artist_style_assert(is_string($source), "$page is niet leesbaar");
  artist_style_assert(str_contains($source, '<div class="lot-artist-name"><?=lot_artist_markup($lot)?></div><span class="lot-artist-label">Kunstenaar:</span>'), "$page: label moet onder de naam staan");
  artist_style_assert(!str_contains($source, 'Kunstenaar: <?=lot_artist_markup($lot)?>'), "$page: label mag niet voor de link staan");
}
artist_style_assert(str_contains($card, 'class="lot-artist"'), 'Cataloguskaart gebruikt een aparte kunstenaarcontainer');
artist_style_assert(str_contains($detail, 'class="detail-artist"'), 'Kaveldetail gebruikt een aparte kunstenaarcontainer');
artist_style_assert(is_string($css) && str_contains($css, '.lot-artist-name a{color:#1856a3;text-decoration:underline'), 'Kunstenaarslink heeft blauwe onderlijnde stijl');
artist_style_assert(str_contains($css, '.lot-artist-name a:hover') && str_contains($css, '.lot-artist-name a:focus-visible'), 'Hover en toetsenbordfocus blijven zichtbaar');
artist_style_assert(str_contains($css, '.lot-artist-label{'), 'Het label heeft een eigen stijl');

$linked = lot_artist_markup(['artist_name'=>'Marf', 'artist_url'=>'https://www.marf.be/']);
artist_style_assert(str_contains($linked, 'href="https://www.marf.be/"'), 'Een geldige kunstenaarslink blijft klikbaar');
artist_style_assert(str_contains($linked, 'rel="noopener noreferrer"'), 'Veilige externe-link-attributen behouden');
artist_style_assert(lot_artist_markup(['artist_name'=>'Marf', 'artist_url'=>'']) === 'Marf', 'Zonder URL verschijnt gewone tekst');
artist_style_assert(!str_contains(lot_artist_markup(['artist_name'=>'Marf','artist_url'=>'javascript:alert(1)']), '<a'), 'Ongeldige links blijven niet klikbaar');

echo "PASS artist attribution layout, accessibility and URL safety\n";
