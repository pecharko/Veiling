<?php
declare(strict_types=1);

/** Ensure lot views are attributed to the catalogue, not detail-arrow navigation. */
$root = dirname(__DIR__);
$home = file_get_contents($root . '/catalogus-public/index.php');
$detail = file_get_contents($root . '/catalogus-public/kavel.php');
if (!is_string($home) || !is_string($detail)) throw new RuntimeException('Missing public pages');

function check_lot_source(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$catalogueLink = "url('kavel.php',['nr'=>(int)\$lot['lot_number'],'bron'=>'lijst'])";
check_lot_source(substr_count($home, $catalogueLink) === 2, 'Both catalogue card links carry the counting marker');
check_lot_source(str_contains($detail, "if ((\$_GET['bron'] ?? null) === 'lijst') track_catalog_view((int)\$lot['id']);"), 'Only marked catalogue entries trigger lot tracking');
check_lot_source(substr_count($detail, "track_catalog_view((int)\$lot['id']);") === 1, 'Tracking call is guarded once');
check_lot_source(str_contains($detail, "url('kavel.php',['nr'=>(int)\$prev])")
    && str_contains($detail, "url('kavel.php',['nr'=>(int)\$next])"), 'Previous and next arrows use unmarked links');
check_lot_source(str_contains($detail, 'href="https://41lokeren.be/kavel.php?nr='), 'Canonical detail URL is unchanged');
echo "PASS: catalogue-only lot view source contract\n";
