<?php
declare(strict_types=1);

// The previous-edition page must link to the other public sections without
// linking to itself or duplicating the current-auction CTA in the header.
$root = dirname(__DIR__);
$page = file_get_contents($root.'/catalogus-public/vorige-editie.php');
if (!is_string($page)) throw new RuntimeException('Previous edition source unavailable.');
if (!preg_match('~<nav class="header-nav front-nav" aria-label="Hoofdnavigatie">(.*?)</nav>~s', $page, $matches)) {
    throw new RuntimeException('Previous edition header navigation missing.');
}
$nav = $matches[1];
foreach ([
    '>Home</a>',
    '>Bekijk kavels</a>',
    "url('index.php')",
    "url('kunstenaars.php')",
    '>Kunstenaars</a>',
    "url('goede-doel.php')",
    '>Het goede doel</a>',
] as $expected) {
    if (!str_contains($nav, $expected)) throw new RuntimeException('Missing previous edition menu item: '.$expected);
}
foreach (['>Vorige editie</a>', "url('vorige-editie.php')", 'Naar de huidige veiling', 'front-header-cta'] as $forbidden) {
    if (str_contains($nav, $forbidden)) throw new RuntimeException('Obsolete previous edition menu item: '.$forbidden);
}
if (!str_contains($page, 'id="impressie"') || !str_contains($page, 'Alderande vzw:')) {
    throw new RuntimeException('Previous edition content must remain intact.');
}
echo "PASS previous edition header links to catalog, artists and charity without redundant actions.\n";
