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
$homepageButton = '<a class="front-btn front-btn-outline" href="<?=e(url(\'index.php\'))?>">Startpagina</a>';
if (substr_count($source, $homepageButton) !== 2 ||
    !str_contains($source, '<div class="artists-overview-bottom-action" style="display:block;margin-top:0;padding-top:clamp(60px,5vw,80px)">')) {
    throw new RuntimeException('Artists overview requires a Startpagina button above and below the artist list.');
}
if (str_contains($source, 'class="back-link"') || str_contains($source, '← Terug naar de catalogus')) {
    throw new RuntimeException('Old artists overview back link must be absent.');
}
if (!str_contains($source, '<h1>Ontmoet onze kunstenaars</h1>')) {
    throw new RuntimeException('Artists overview heading must remain intact.');
}
$css = file_get_contents(dirname(__DIR__) . '/catalogus-public/assets/catalogus.css');
if (!is_string($css) || !str_contains($css, '.artists-overview .front-btn-outline{')
    || !str_contains($css, '.artists-overview .front-btn{')) {
    throw new RuntimeException('Artist directory homepage-style return button must have scoped CSS.');
}
if (!str_contains($source, "catalogus.css'))?>?v=196")) {
    throw new RuntimeException('Artist directory must bust stylesheet cache for updated button style.');
}
echo "PASS artist navigation includes charity link and homepage-style return button without self-link.\n";
