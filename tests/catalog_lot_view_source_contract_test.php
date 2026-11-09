<?php
declare(strict_types=1);

/** Count all detail visits except explicit previous/next navigation. */
$root = dirname(__DIR__);
$home = file_get_contents($root . '/catalogus-public/index.php');
$detail = file_get_contents($root . '/catalogus-public/kavel.php');
if (!is_string($home) || !is_string($detail)) throw new RuntimeException('Missing public pages');

function check_lot_source(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$catalogueLink = "url('kavel.php',['nr'=>(int)\$lot['lot_number']])";
check_lot_source(substr_count($home, $catalogueLink) === 2, 'Both catalogue card links use ordinary counted detail URLs');
check_lot_source(str_contains($detail, "if ((\$_GET['bron'] ?? null) !== 'navigatie') track_catalog_view((int)\$lot['id']);"),
    'Direct and shared URLs count; only marked arrow navigation is excluded');
check_lot_source(substr_count($detail, "track_catalog_view((int)\$lot['id']);") === 1, 'Tracking call is guarded exactly once');
check_lot_source(str_contains($detail, "url('kavel.php',['nr'=>(int)\$prev,'bron'=>'navigatie'])")
    && str_contains($detail, "url('kavel.php',['nr'=>(int)\$next,'bron'=>'navigatie'])"),
    'Both previous and next navigation links explicitly opt out of counting');
check_lot_source(str_contains($detail, 'href="https://41lokeren.be/kavel.php?nr='), 'Canonical detail URL remains clean');
echo "PASS: direct/shared/catalogue views count; detail arrows do not.\n";
