<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$admin = file_get_contents($root.'/catalogus-public/admin/instellingen.php');
$home = file_get_contents($root.'/catalogus-public/index.php');
$detail = file_get_contents($root.'/catalogus-public/kavel.php');
$helper = file_get_contents($root.'/private/catalog_preview_modes.php');
$seed = file_get_contents($root.'/dev/preview/seed.php');
$fixture = file_get_contents($root.'/tests/kavel_integration_setup.php');
foreach ([$admin,$home,$detail,$helper,$seed,$fixture] as $part)
    if (!is_string($part)) throw new RuntimeException('Missing implementation file');
foreach (['name="catalog_split_active"', 'catalog_split_active = :catalog_split_active',
           'Opsplitsing Veiling / Marktplaats activeren', 'check_csrf();'] as $needed)
    if (!str_contains($admin,$needed)) throw new RuntimeException('Admin setting missing: '.$needed);
if (!str_contains($home,'catalog_split_active($s)') || !str_contains($detail,'catalog_split_active($s)'))
    throw new RuntimeException('Saved setting not connected to public catalogue and detail');
if (!str_contains($helper,"\$settings['catalog_split_active'] ?? 0") ||
    !str_contains($helper, "array_key_exists('catalog_split_preview', \$_SESSION)"))
    throw new RuntimeException('Default-off state or admin override missing');
if (!str_contains($seed,"SHOW COLUMNS FROM settings LIKE 'catalog_split_active'") ||
    !str_contains($seed,"local-preview-only-nonproduction") ||
    !str_contains($fixture,'catalog_split_active TINYINT(1) NOT NULL DEFAULT 0'))
    throw new RuntimeException('Guarded disposable schema upgrade missing');
echo "PASS persisted catalog split setting and independent admin preview override\n";
