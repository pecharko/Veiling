<?php
declare(strict_types=1);

// Preserve navigation to the homepage, catalog, artists and previous edition,
// without linking to the current charity page or duplicating its catalog CTA.
$source = file_get_contents(dirname(__DIR__).'/catalogus-public/goede-doel.php');
if (!is_string($source)) throw new RuntimeException('Good cause page unavailable.');
if (!preg_match('~<nav class="header-nav front-nav" aria-label="Hoofdnavigatie">(.*?)</nav>~s', $source, $matches)) {
    throw new RuntimeException('Good cause header missing.');
}
$nav = $matches[1];
foreach ([
    '>Home</a>',
    "url('index.php')",
    '>Bekijk kavels</a>',
    "url('kunstenaars.php')",
    '>Kunstenaars</a>',
    "url('vorige-editie.php')",
    '>Vorige editie</a>',
] as $required) {
    if (!str_contains($nav, $required)) throw new RuntimeException('Good cause header missing: '.$required);
}
foreach ([">Het goede doel</a>", "url('goede-doel.php')", 'Naar de veiling', 'front-header-cta'] as $forbidden) {
    if (str_contains($nav, $forbidden)) throw new RuntimeException('Redundant good cause nav item: '.$forbidden);
}
$homePos = strpos($nav, '>Home</a>');
$catalogPos = strpos($nav, '>Bekijk kavels</a>');
$artistsPos = strpos($nav, '>Kunstenaars</a>');
$previousPos = strpos($nav, '>Vorige editie</a>');
if (!($homePos < $catalogPos && $catalogPos < $artistsPos && $artistsPos < $previousPos)) {
    throw new RuntimeException('Good cause header order is incorrect.');
}
if (!str_contains($source, 'Terug naar de startpagina')) {
    throw new RuntimeException('Homepage return button must remain.');
}
echo "PASS good cause header includes catalog and artists after Home without redundant links.\n";
