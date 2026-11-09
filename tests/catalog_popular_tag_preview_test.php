<?php
declare(strict_types=1);
// Offline popularity contract. No analytics writes, no personal visitor data in public HTML.
$root = dirname(__DIR__);
$index = file_get_contents($root . '/catalogus-public/index.php');
$css = file_get_contents($root . '/catalogus-public/assets/catalogus.css');
if (!is_string($index) || !is_string($css)) throw new RuntimeException('Catalog source missing');
function popular_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
popular_assert(str_contains($index, "visit_date BETWEEN UTC_DATE() - INTERVAL 6 DAY AND UTC_DATE()"), 'Seven UTC days, including today');
popular_assert(substr_count($index, "visit_date BETWEEN UTC_DATE() - INTERVAL 6 DAY AND UTC_DATE()") === 2, 'Same period for raw and archived views');
popular_assert(str_contains($index, "FROM visitor_stats_daily") && str_contains($index, "FROM visitor_stats_lot_totals"), 'Raw and archived view sources');
popular_assert(str_contains($index, "page_kind='lot'") && str_contains($index, "UNION ALL"), 'Only lot views, union archived totals');
popular_assert(str_contains($index, "l.status IN ('published','sold')"), 'Only visible lots compete');
popular_assert(str_contains($index, 'GROUP BY l.id, l.sale_location') && str_contains($index, 'ORDER BY total_views DESC, l.id ASC'), 'Deterministic ranking per lot');
popular_assert(str_contains($index, 'HAVING SUM(v.views) > 0'), 'Never mark lots without views');
popular_assert(str_contains($index, "\$group = \$splitPreview ? (string)\$popularLot['sale_location'] : 'alle';"), 'Separate sale-location groups when split');
popular_assert(str_contains($index, "\$popularCounts = ['veiling'=>0, 'markt'=>0, 'alle'=>0];"), 'Independent group counters');
popular_assert(str_contains($index, "\$popularCounts[\$group] >= 5"), 'No more than five per group');
popular_assert(str_contains($index, "\$popularLotIds[(int)\$popularLot['lot_id']] = true;"), 'Ranking keyed by stable database lot ID');
popular_assert(str_contains($index, 'isset($popularLotIds[(int)$lot[\'id\']])'), 'Tag only on ranked catalog cards, regardless of page');
popular_assert(str_contains($index, '>Populair</span>'), 'Populair tag shown in lot cover');
popular_assert(str_contains($index, 'catch (PDOException $exception)'), 'Missing statistics fail safely');
popular_assert(str_contains($css, '.lot-popular-tag{position:absolute;top:12px;right:12px;'), 'Visible, non-obscuring card tag');
popular_assert(str_contains($css, '.lot-popular-tag--sold{top:88px}') && str_contains($css, '@media(max-width:800px)'), 'Sold and mobile layout');
echo "PASS: popularity is top five over seven UTC days, per group when split, with no visitor details.\n";
