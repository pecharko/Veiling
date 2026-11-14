<?php
declare(strict_types=1);
// Test the standalone markup helper without connecting to a database.
if (!function_exists('url')) {
  function url(string $path, array $query=[]): string {
    return '/'.$path.($query ? '?'.http_build_query($query) : '');
  }
}
require_once dirname(__DIR__) . '/private/lot_artist.php';

function artist_style_assert(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

$card = file_get_contents(__DIR__ . '/../catalogus-public/index.php');
$detail = file_get_contents(__DIR__ . '/../catalogus-public/kavel.php');
$css = file_get_contents(__DIR__ . '/../catalogus-public/assets/catalogus.css');

artist_style_assert(is_string($card) && is_string($detail), 'Cataloguskaart en kaveldetail zijn leesbaar');
artist_style_assert(str_contains($card, '<?=lot_artist_markup($lot)?>'), 'Catalogus gebruikt veilige kunstenaarslinkhelper');
artist_style_assert(str_contains($detail, '<?=lot_artist_markup($lot)?>'), 'Kaveldetail gebruikt veilige kunstenaarslinkhelper');
artist_style_assert(str_contains($card, 'AS published_artist_id'), 'Catalogus bepaalt publicatiestatus bij de databasequery');
artist_style_assert(str_contains($detail, 'AS published_artist_id'), 'Kaveldetail bepaalt publicatiestatus bij de databasequery');
artist_style_assert(str_contains($card, 'class="lot-artist"'), 'Cataloguskaart gebruikt een aparte kunstenaarcontainer');
artist_style_assert(str_contains($detail, 'class="detail-artist"'), 'Kaveldetail gebruikt een aparte kunstenaarcontainer');
artist_style_assert(is_string($css) && str_contains($css, '.lot-artist-name a{color:#1856a3;text-decoration:underline'), 'Kunstenaarslink heeft blauwe onderlijnde stijl');
artist_style_assert(str_contains($css, '.lot-artist-name a:hover') && str_contains($css, '.lot-artist-name a:focus-visible'), 'Hover en toetsenbordfocus blijven zichtbaar');
artist_style_assert(str_contains($css, '.lot-artist-label{'), 'Het label heeft een eigen stijl');
artist_style_assert(str_contains($css, '.lot-artist,.detail-artist{display:flex') && str_contains($css, 'text-overflow:ellipsis;white-space:nowrap'), 'Label en kunstenaarsnaam blijven op één regel met afkapping');

$linked = lot_artist_markup(['artist_name'=>'Marf', 'published_artist_id'=>7]);
artist_style_assert(str_contains($linked, 'kunstenaar.php?id=7'), 'Gepubliceerde kunstenaar linkt naar eigen pagina');
artist_style_assert(!str_contains($linked, 'https://www.marf.be'), 'Externe kunstenaarswebsite wordt niet gebruikt voor profiel');
artist_style_assert(lot_artist_markup(['artist_name'=>'Marf', 'published_artist_id'=>0]) === 'Marf', 'Niet gepubliceerd geeft gewone tekst');
artist_style_assert(lot_artist_markup(['artist_name'=>'Marf']) === 'Marf', 'Zonder publicatieveld geen link');
artist_style_assert(lot_artist_markup(['artist_name'=>'<script>', 'published_artist_id'=>0]) === '&lt;script&gt;', 'Niet gepubliceerde naam wordt ontsmet');

echo "PASS artist attribution layout, accessibility and URL safety\n";
