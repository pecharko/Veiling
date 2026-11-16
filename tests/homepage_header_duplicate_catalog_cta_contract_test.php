<?php
declare(strict_types=1);

// The rightmost duplicate catalog CTA must be removed without affecting
// the primary catalog navigation or the hero buttons.
$home = file_get_contents(dirname(__DIR__) . '/catalogus-public/index.php');
if (!is_string($home)) throw new RuntimeException('Homepage source unavailable.');
if (!preg_match('~<nav class="header-nav front-nav" aria-label="Hoofdnavigatie">(.*?)</nav>~s', $home, $matches)) {
    throw new RuntimeException('Homepage navigation not found.');
}
$nav = $matches[1];
if (str_contains($nav, 'front-header-cta') || str_contains($nav, 'Bekijk kavels ↗')) {
    throw new RuntimeException('Redundant far-right catalog header CTA still exists.');
}
if (str_contains($nav, '>Home</a>') || str_contains($nav, 'front-nav-home')) {
    throw new RuntimeException('Homepage header must not link to the page itself.');
}
foreach (['>Bekijk kavels</a>', 'id="favoriteLink"', '>Kunstenaars</a>', '>Het goede doel</a>', '>Vorige editie</a>', "url('vorige-editie.php')"] as $expected) {
    if (!str_contains($nav, $expected)) throw new RuntimeException('Expected header navigation missing: '.$expected);
}
if (!str_contains($home, 'Bekijk de kavels <span aria-hidden="true">↗</span>')
    || !str_contains($home, 'Maak kennis met de deelnemende kunstenaars')) {
    throw new RuntimeException('Homepage hero CTAs must remain intact.');
}
echo "PASS homepage duplicate catalog header CTA removed while navigation and hero buttons remain.\n";
