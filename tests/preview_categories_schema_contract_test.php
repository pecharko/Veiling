<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$seed=file_get_contents($root.'/dev/preview/seed.php');
$script=file_get_contents($root.'/scripts/preview');
if (!is_string($seed)||!is_string($script)) throw new RuntimeException('Missing preview files');
function checkPreview(bool $ok,string $reason): void { if(!$ok)throw new RuntimeException($reason); }
checkPreview(str_contains($seed,"getenv('LOCAL_PREVIEW_ISOLATED') !== '1'"),'Preview seed must be isolated');
checkPreview(str_contains($seed,"cfg('setup_key') !== 'local-preview-only-nonproduction'"),'Preview setup key required');
checkPreview(str_contains($seed,"SHOW TABLES LIKE 'lot_categories'"),'Idempotent missing-table detection');
checkPreview(str_contains($seed,"20261110_lot_categories.sql"),'Use reviewed category migration');
checkPreview(str_contains($seed,"UPDATE lots SET category='' WHERE category<>'';"),'Seed validates one-time category clear');
checkPreview(strpos($seed,"SHOW TABLES LIKE 'lot_categories'")<strpos($seed,"SELECT COUNT(*) FROM lots"),'Schema upgrade before existing-lot early return');
checkPreview(str_contains($script,"ONTBREEKT: lot_categories in preview-MySQL"),'Diagnostic explains missing table');
echo "PASS isolated preview category upgrade contract\\n";
