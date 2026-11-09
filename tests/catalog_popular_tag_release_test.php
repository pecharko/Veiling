<?php
declare(strict_types=1);
// Same 7-day top-five algorithm must drive both public tags and admin statistics.
$root = dirname(__DIR__);
$catalog = file_get_contents($root . '/catalogus-public/index.php');
$admin = file_get_contents($root . '/catalogus-public/admin/statistieken.php');
$helper = file_get_contents($root . '/private/catalog_popular_lots.php');
$css = file_get_contents($root . '/catalogus-public/assets/catalogus.css');
$fixture = file_get_contents($root . '/dev/preview/seed.php');
if (!is_string($catalog) || !is_string($admin) || !is_string($helper)
    || !is_string($css) || !is_string($fixture)) throw new RuntimeException('Missing popularity source');
function popular_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
popular_assert(str_contains($helper, "visit_date BETWEEN UTC_DATE() - INTERVAL 6 DAY AND UTC_DATE()")
    && substr_count($helper, "visit_date BETWEEN UTC_DATE() - INTERVAL 6 DAY AND UTC_DATE()") === 2, 'Seven UTC days across both sources');
popular_assert(str_contains($helper, 'FROM visitor_stats_daily') && str_contains($helper, 'FROM visitor_stats_lot_totals')
    && str_contains($helper, "page_kind='lot'") && str_contains($helper, 'UNION ALL'), 'Only raw and archived lot views');
popular_assert(str_contains($helper, "l.status IN ('published','sold')") && str_contains($helper, 'HAVING SUM(v.views) > 0'), 'Only published, viewed lots');
popular_assert(str_contains($helper, 'ORDER BY total_views DESC, l.id ASC') && str_contains($helper, 'count($groups[$group]) >= 5'), 'Stable top-five per group');
popular_assert(str_contains($helper, '$group = $splitActive ? (string)$lot[\'sale_location\'] : \'alle\';'), 'Separate veiling and markt rankings');
popular_assert(str_contains($catalog, 'catalog_popular_lots($pdo, $splitPreview)'), 'Public catalog uses shared ranking');
popular_assert(str_contains($catalog, 'isset($popularLotIds[(int)$lot[\'id\']])'), 'Public badge checks ranked IDs');
popular_assert(str_contains($admin, 'catalog_popular_lots($pdo, $popularSplitActive)'), 'Admin uses identical shared ranking');
popular_assert(str_contains($admin, '$popularSplitActive = (int)(settings()[\'catalog_split_active\'] ?? 0) === 1;'), 'Admin follows real split activation');
popular_assert(str_contains($admin, 'Veiling — top 5') && str_contains($admin, 'Marktplaats — top 5'), 'Admin displays both groups');
popular_assert(str_contains($admin, "title'])?></a>") && str_contains($admin, "total_views"), 'Admin links to lot and shows view counts');
popular_assert(str_contains($admin, "Nog geen bekeken kavels"), 'Empty groups clearly explained');
popular_assert(str_contains($css, '.lot-popular-tag{position:absolute;top:12px;right:12px;'), 'Visible tag styling');
popular_assert(str_contains($catalog, 'catch (PDOException $exception)'), 'Statistics failure never blocks public catalog');
popular_assert(str_contains($catalog, '>Populair</span>'), 'Public card has the visible tag');
popular_assert(str_contains($admin, 'Populaire kavels — laatste 7 dagen') && str_contains($admin, 'Top 10 meest bekeken kavels'), 'Admin includes popular and historical top ten');
popular_assert(str_contains($css, '.lot-popular-tag--sold{top:88px}'), 'Popular and sold badge layout');
echo "PASS: catalog and admin share seven-day top-five rankings.\\n";
