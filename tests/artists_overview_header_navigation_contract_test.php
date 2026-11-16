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
if (!str_contains($source, '<a class="back-link" href="<?=e(url(\'index.php\'))?>#catalogus">← Terug</a>')) {
    throw new RuntimeException('Artists overview back link must say Terug and retain catalog destination.');
}
if (str_contains($source, '← Terug naar de catalogus')) {
    throw new RuntimeException('Old back link wording must be absent.');
}
if (!str_contains($source, '<h1>Ontmoet onze kunstenaars</h1>')) {
    throw new RuntimeException('Artists overview heading must remain intact.');
}
echo "PASS artist navigation includes charity link and short catalog back link without self-link.\n";
