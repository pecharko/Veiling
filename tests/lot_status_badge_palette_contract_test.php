<?php
declare(strict_types=1);

// Offline, read-only regression: status values and seller palette remain stable.
$root = dirname(__DIR__);
$list = file_get_contents($root . '/catalogus-public/admin/index.php');
$editor = file_get_contents($root . '/catalogus-public/admin/kavel.php');
$css = file_get_contents($root . '/catalogus-public/admin/admin.css');
if (!is_string($list) || !is_string($editor) || !is_string($css)) {
    throw new RuntimeException('Kavelstatusbron ontbreekt.');
}
foreach ([
    "'draft'=>'Nieuw'",
    "'published'=>'Gepubliceerd'",
    "'sold'=>'Verkocht'",
    "'canceled'=>'Canceled'",
    "'draft'=>'is-new'",
    "'published'=>'is-published'",
    "'sold'=>'is-sold'",
    "'canceled'=>'is-canceled'",
    'option value="draft"',
] as $required) {
    if (!str_contains($list, $required)) {
        throw new RuntimeException('Kavellijst mist statuscontract: ' . $required);
    }
}
if (str_contains($list, '● Concept') || str_contains($list, '>Concept</option>')) {
    throw new RuntimeException('Oude zichtbare status Concept blijft in kavellijst.');
}
if (str_contains($editor, 'Concept — niet zichtbaar') ||
    !str_contains($editor, 'Nieuw — niet zichtbaar') ||
    !str_contains($editor, "['status'] ?? 'draft'")) {
    throw new RuntimeException('Kavelbewerker moet Nieuw tonen en draft behouden.');
}
$palette = [
    'is-new' => ['#e7f0ff', '#1952a0'],
    'is-published' => ['#e4f6e9', '#195b32'],
    'is-unpublished' => ['#fde9e8', '#a12424'],
];
foreach ($palette as $state => [$background, $ink]) {
    $sellerRule = ".seller-publication-status.$state{background:$background;color:$ink}";
    if (!str_contains($css, $sellerRule)) {
        throw new RuntimeException('Verkoperskleur gewijzigd voor ' . $state);
    }
}
foreach ([
    '.dashboard-badge.is-new{background:#e7f0ff;color:#1952a0}',
    '.dashboard-badge.is-published{background:#e4f6e9;color:#195b32}',
    '.dashboard-badge.is-sold{background:#17683a;color:#fff}',
    '.dashboard-badge.is-canceled{background:#fde9e8;color:#a12424}',
] as $rule) {
    if (!str_contains($css, $rule)) {
        throw new RuntimeException('Kavelkleur volgt verkoperspalet niet: ' . $rule);
    }
}
echo "PASS: kavelstatus Nieuw, ongewijzigde databasewaarden en verkoperskleuren.\n";
