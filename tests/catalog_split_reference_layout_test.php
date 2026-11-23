<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$home=file_get_contents($root.'/catalogus-public/index.php');
$css=file_get_contents($root.'/catalogus-public/assets/catalogus.css');
if (!is_string($home) || !is_string($css)) throw new RuntimeException('Layout files missing');
foreach (['catalog-preview-explain-copy', 'catalog-preview-tab-icon', 'catalogus-verkocht-veilinghamer.webp', 'catalog-preview-help', 'Bied mee tijdens de benefietveiling', 'Koop het meteen tijdens de kijkdagen'] as $piece) {
    if (!str_contains($home,$piece)) throw new RuntimeException('Reference layout incomplete: '.$piece);
}
if (!preg_match('~id="catalogus" aria-labelledby="catalogus-titel">\s*<\?php if \(\$splitPreview\): \?>(.*?)<\?php endif; \?>.*?<\?php else: \?>(.*?)<\?php endif; \?>~s', $home, $catalogIntro)
    || str_contains($catalogIntro[1], 'class="section-lead"')
    || substr_count($catalogIntro[1], '<h2 id="catalogus-titel">Veiling en marktplaats.</h2>') !== 1
    || !str_contains($catalogIntro[1], 'class="catalog-preview-explain-copy front-recruit-copy"')
    || !str_contains($catalogIntro[1], '<p class="front-recruit-lead">Ontdek bijzondere stukken en kies hoe je meedoet.</p>')
    || !str_contains($home, "\$splitPreview ? ' catalog-section--split' : ' shell'")
    || !preg_match('~class="shell front-recruit-artist-grid".*?class="front-recruit-photo".*?class="catalog-preview-explain-copy front-recruit-copy".*?class="catalog-preview-tabs front-recruit-benefits"~s', $catalogIntro[1])
    || !str_contains($css, '.catalog-section.catalog-section--split{padding-top:0}')) {
    throw new RuntimeException('The split catalog needs the recruitment grid, typography and no top gap.');
}
if (!preg_match('~class="section-lead">\s*<div class="front-recruit-copy">\s*<h2 id="catalogus-titel">Ontdek de kavels\.</h2>~', $catalogIntro[2])
    || str_contains($catalogIntro[2], 'dark-eyebrow')
    || !str_contains($css, '.catalog-section{padding-top:24px;')) {
    throw new RuntimeException('The unsplit catalog needs the shared heading style, no eyebrow and compact top spacing.');
}
if (substr_count($home,'data-icon="auction-gavel"') !== 2) throw new RuntimeException('Exactly two matching gavel icons required');
if (substr_count($home,'transform="rotate(35 24 24)"') !== 2) throw new RuntimeException('Aligned gavel heads and handles missing');
if (substr_count($home,'<svg viewBox="0 0 48 48"') < 4) throw new RuntimeException('SVG icons missing');
foreach (['.catalog-preview-tabs .front-recruit-button.active','catalog-preview-help strong','@media(max-width:650px)'] as $rule) {
    if (!str_contains($css,$rule)) throw new RuntimeException('Reference responsive design missing: '.$rule);
}
echo "PASS catalog split reference layout, icons and mobile rules\n";
