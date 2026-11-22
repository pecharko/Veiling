<?php
declare(strict_types=1);

// The artists overview must not link to itself from its own top navigation.
$source = file_get_contents(dirname(__DIR__) . '/catalogus-public/kunstenaars.php');
if (!is_string($source)) {
    throw new RuntimeException('Artists overview source unavailable.');
}
if (!preg_match('~<nav class="header-nav" aria-label="Hoofdnavigatie">(.*?)</nav>~s', $source, $match)) {
    throw new RuntimeException('Artists overview navigation missing.');
}
$nav = $match[1];
if (str_contains($nav, "url('kunstenaars.php')") || str_contains($nav, '>Kunstenaars</a>')) {
    throw new RuntimeException('Artists overview should not show redundant Kunstenaars menu item.');
}
foreach (["url('index.php')", '>Home</a>', '>Bekijk kavels</a>', "url('goede-doel.php')", '>Het goede doel</a>', "url('vorige-editie.php')", '>Vorige editie</a>'] as $expected) {
    if (!str_contains($nav, $expected)) {
        throw new RuntimeException('Required navigation item missing: ' . $expected);
    }
}
$homepageLink = '<a class="back-link" href="<?=e(url(\'index.php\'))?>">← Terug naar startpagina</a>';
if (substr_count($source, $homepageLink) !== 2 ||
    !str_contains($source, '<div class="artists-overview-bottom-action" style="display:block;margin-top:0;padding-top:clamp(60px,5vw,80px)">')) {
    throw new RuntimeException('Artists overview requires a homepage return link above and below the artist list.');
}
if (str_contains($source, '← Terug naar de catalogus')) {
    throw new RuntimeException('Artists overview return links must go to the homepage rather than the catalog anchor.');
}
if (!str_contains($source, '<h1>Ontmoet onze kunstenaars</h1>')) {
    throw new RuntimeException('Artists overview heading must remain intact.');
}
if (!str_contains($source, "catalogus.css'))?>?v=196")) {
    throw new RuntimeException('Artist directory must bust stylesheet cache for updated button style.');
}
echo "PASS artist navigation and shared homepage return links.\n";
