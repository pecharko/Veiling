<?php
declare(strict_types=1);
// Offline contract: session preview override does not write public settings.
$root=dirname(__DIR__);
$home=file_get_contents($root.'/catalogus-public/index.php');
$bootstrap=file_get_contents($root.'/private/bootstrap.php');
$detail=file_get_contents($root.'/catalogus-public/kavel.php');
$helper=file_get_contents($root.'/private/catalog_preview_modes.php');
foreach (['split_on','split_off','check_csrf();','admin_preview_active()','catalog_split_preview'] as $fragment)
    if (!str_contains($home,$fragment)) throw new RuntimeException('Missing secure session toggle '.$fragment);
foreach (['Opsplitsing aan','Opsplitsing uit'] as $fragment)
    if (!str_contains($bootstrap,$fragment)) throw new RuntimeException('Admin banner toggle missing');
if (!str_contains($home,'l.auction_number ASC') || !str_contains($home,'catalog-preview-tabs') || !str_contains($detail,'catalog_preview_price')) throw new RuntimeException('Catalog preview missing');
if (!str_contains($helper,'admin_preview_active()') || !str_contains($helper,"'catalog_split_active'") || !str_contains($helper,"'Startbod'") || !str_contains($helper,"'Marktprijs'")) throw new RuntimeException('Preview override or price guard missing');
if (str_contains($home,'UPDATE settings') || str_contains($helper,'UPDATE settings')) throw new RuntimeException('Preview must not change public settings');
echo "PASS admin-only catalog split preview toggle and conditional displays\n";
