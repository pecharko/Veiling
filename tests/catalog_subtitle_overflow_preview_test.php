<?php
declare(strict_types=1);
// Offline contract: even a single unbroken subtitle stays inside the card.
$root = dirname(__DIR__);
$css = file_get_contents($root . '/catalogus-public/assets/catalogus.css');
$catalog = file_get_contents($root . '/catalogus-public/index.php');
if (!is_string($css) || !is_string($catalog)) throw new RuntimeException('Catalog source unavailable');
if (!preg_match('~\\.lot-card \\.lot-subtitle\\{[^}]*min-width:0;max-width:100%;overflow-wrap:anywhere;word-break:normal\\}~', $css)) {
  throw new RuntimeException('Catalog subtitle must wrap uninterrupted strings inside card width');
}
if (!str_contains($catalog, '<p class="lot-subtitle"><?=e($lot[\'subtitle\'])?></p>')) {
  throw new RuntimeException('Full subtitle must remain in escaped HTML, no data truncation');
}
echo "PASS: long catalog subtitles wrap inside desktop/mobile card.\n";
