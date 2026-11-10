<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$detail = file_get_contents($root . '/catalogus-public/kavel.php');
$css = file_get_contents($root . '/catalogus-public/assets/catalogus.css');
if (!is_string($detail) || !is_string($css)) throw new RuntimeException('Missing detail badge files');
function badges_expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$photoStart = strpos($detail, '<div class="main-photo">');
$photoEnd = strpos($detail, '</div>', $photoStart === false ? 0 : $photoStart);
$photo = $photoStart === false || $photoEnd === false ? '' : substr($detail, $photoStart, $photoEnd - $photoStart);
badges_expect(str_contains($photo, 'lot-sold-badge detail-sold-badge')
    && str_contains($photo, 'lot-popular-tag detail-popular-tag'), 'Both badges must overlay the main photo');
badges_expect(!str_contains($detail, '<div class="detail-copy"><?php if ($lot[\'status\'] === \'sold\')'), 'Sold badge must leave detail text');
badges_expect(str_contains($css, '.main-photo .detail-sold-badge{position:absolute;top:20px;left:14px;right:auto;'), 'Sold badge at upper left');
badges_expect(str_contains($css, '.main-photo .detail-popular-tag{position:absolute;top:20px;right:14px;'), 'Popular badge at upper right');
badges_expect(str_contains($css, 'min-width:118px;padding:12px 16px 11px;border-radius:2px;font-size:13px'), 'Popular badge matches sold badge dimensions on desktop');
badges_expect(str_contains($css, '.main-photo .detail-popular-tag{top:16px;right:10px;min-width:100px;padding:9px 9px;font-size:10px}'), 'Mobile popular badge matches sold badge dimensions');
badges_expect(str_contains($detail, '?v=189'), 'Detail page cache-busts its changed CSS');
echo "PASS: detail badges have equal dimensions and opposite upper corners.\n";
