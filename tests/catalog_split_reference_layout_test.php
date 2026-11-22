<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$home=file_get_contents($root.'/catalogus-public/index.php');
$css=file_get_contents($root.'/catalogus-public/assets/catalogus.css');
if (!is_string($home) || !is_string($css)) throw new RuntimeException('Layout files missing');
foreach (['catalog-preview-explain-copy', 'catalog-preview-tab-icon', 'catalog-preview-icon--gavel', 'catalog-preview-help', 'Bied mee tijdens de benefietveiling', 'Koop het meteen tijdens de kijkdagen'] as $piece) {
    if (!str_contains($home,$piece)) throw new RuntimeException('Reference layout incomplete: '.$piece);
}
if (!preg_match('~id="catalogus" aria-labelledby="catalogus-titel">\s*<\?php if \(\$splitPreview\): \?>(.*?)<\?php else: \?>(.*?)<\?php endif; \?>~s', $home, $catalogIntro)
    || str_contains($catalogIntro[1], 'class="section-lead"')
    || substr_count($catalogIntro[1], '<h2 id="catalogus-titel">Veiling en marktplaats') !== 1
    || !str_contains($catalogIntro[2], '<h2 id="catalogus-titel">Ontdek de kavels')
    || !str_contains($css, '.section-lead h2,.catalog-preview-explain h2{')) {
    throw new RuntimeException('The split catalog needs one title inside the explanation, with the shared catalog heading style.');
}
if (substr_count($home,'data-icon="auction-gavel"') !== 3) throw new RuntimeException('Exactly three matching gavel icons required');
if (substr_count($home,'transform="rotate(35 24 24)"') !== 3) throw new RuntimeException('Aligned gavel heads and handles missing');
if (substr_count($home,'<svg viewBox="0 0 48 48"') < 5) throw new RuntimeException('SVG icons missing');
foreach (['grid-template-columns:repeat(2,minmax(0,1fr))','catalog-preview-tabs a.active','catalog-preview-help strong','@media(max-width:650px)'] as $rule) {
    if (!str_contains($css,$rule)) throw new RuntimeException('Reference responsive design missing: '.$rule);
}
echo "PASS catalog split reference layout, icons and mobile rules\n";
