<?php
declare(strict_types=1);
// Offline UI contract for the combined artist brochure and previous-edition cleanup.
$root = dirname(__DIR__);
$artist = file_get_contents($root . '/catalogus-public/kunstenaar.php');
$previous = file_get_contents($root . '/catalogus-public/vorige-editie.php');
$css = file_get_contents($root . '/catalogus-public/assets/catalogus.css');
if (!is_string($artist) || !is_string($previous) || !is_string($css)) {
    throw new RuntimeException('Missing public page or stylesheet');
}
if (str_contains($previous, 'Beleef de sfeer') ||
    str_contains($previous, 'Een geslaagde editie, in negen beelden.') ||
    str_contains($previous, 'impression-hero-footnote')) {
    throw new RuntimeException('Previous-edition hero call-to-action or footnote remains');
}
if (!str_contains($previous, 'id="in-beeld"') ||
    !str_contains($previous, 'impression-hero-visual')) {
    throw new RuntimeException('Previous-edition gallery or photo was unexpectedly removed');
}
if (!str_contains($artist, 'assets/catalogus.css') || !str_contains($artist, '?v=198')) {
    throw new RuntimeException('Artist CSS cache version not refreshed');
}
$marker = '/* Compactere kunstenaarsbrochure - lokale preview */';
if (substr_count($css, $marker) !== 1) {
    throw new RuntimeException('Compact artist CSS should be present exactly once');
}
foreach ([
    '.artist-brochure-sheet',
    'max-width: 960px;',
    'max-height: 390px;',
    'max-height: 350px;',
    'font-size: clamp(16px, 1.8vw, 21px);',
    '@media (max-width: 700px)',
    'font-size: 16px;',
] as $required) {
    if (!str_contains(substr($css, strpos($css, $marker)), $required)) {
        throw new RuntimeException('Missing compact artist style: ' . $required);
    }
}
echo "PASS compact artist brochure and previous-edition hero cleanup.\n";
